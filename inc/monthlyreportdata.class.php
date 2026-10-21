<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Camada de dados do Relatório Mensal
 * -------------------------------------------------------------------------
 *
 * Toda a lógica de consulta fica aqui. A tela (front/monthly_report.php)
 * e a exportação CSV apenas consomem o array devolvido por build().
 *
 * Duas visões distintas, sempre rotuladas na tela:
 *   - Chamados ABERTOS no mês  (por glpi_tickets.date): status, origem, tipo,
 *     comparativo com o mês anterior e top técnicos.
 *   - Chamados SOLUCIONADOS no mês (por glpi_tickets.solvedate): SLA,
 *     tempo médio e distribuição do tempo de solução.
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

class PluginAnalyticsMonthlyReportData
{
    public const TOP_TECHS      = 5;
    public const MIN_TECH_ROWS  = 3;
    public const TOP_RANKING    = 10; // solicitantes e categorias
    public const TOP_GROUPS     = 5;

    public const ORIGIN_OTHER = 'Outras origens';

    /**
     * Agrupamento das origens (glpi_requesttypes.name) nas 4 categorias do
     * modelo do relatório. A busca é por trecho do nome (sem diferenciar
     * maiúsculas). Ajuste aqui conforme os nomes das origens no seu GLPI.
     * O que não casar com nenhuma vai para "Outras origens".
     */
    public const ORIGIN_KEYWORDS = [
        'E-mail'                   => ['mail'],
        'Telefone'                 => ['telefone', 'phone', 'fone', 'ligaç', 'ligac'],
        'Portal / Autoatendimento' => ['portal', 'helpdesk', 'autoatend', 'self'],
        'WhatsApp / Emergencial'   => ['whatsapp', 'whats', 'teams', 'emergenc'],
    ];

    public const ORIGIN_DESCRIPTIONS = [
        'E-mail'                   => 'Chamados Por E-mail',
        'Telefone'                 => 'Chamados abertos por telefone',
        'Portal / Autoatendimento' => 'Chamados abertos pelo portal de atendimento',
        'WhatsApp / Emergencial'   => 'Chamados abertos diretamente pelo WhatsApp/Microsoft Teams',
        self::ORIGIN_OTHER         => 'Chamados abertos por outras origens',
    ];

    public const BUCKETS = [
        'lt1' => ['< 1 dia',                   'Chamados resolvidos em menos de 1 dia'],
        'd13' => ['1 – 3 dias',                'Chamados resolvidos entre 1 e 3 dias'],
        'd37' => ['3 – 7 dias',                'Chamados resolvidos entre 3 e 7 dias'],
        'gt7' => ['Mais de 7 dias (> 7 dias)', 'Chamados resolvidos em mais de 7 dias'],
    ];

    private static function originGroup(string $name): string
    {
        $n = mb_strtolower($name);
        foreach (self::ORIGIN_KEYWORDS as $group => $keywords) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($n, $kw)) {
                    return $group;
                }
            }
        }

        return self::ORIGIN_OTHER;
    }

    /**
     * Limites [início, fim] do mês, no formato de datetime do GLPI.
     *
     * @return array{0: string, 1: string}
     */
    public static function monthBounds(int $year, int $month): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end   = $start->modify('last day of this month')->setTime(23, 59, 59);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * Monta o WHERE de um período sem chaves duplicadas.
     * (Usar duas vezes a chave 'date' no mesmo array faz a segunda sobrescrever
     * a primeira; por isso cada condição vai em um sub-array numérico.)
     */
    private static function where(
        string $table,
        string $dateField,
        string $from,
        string $to,
        array $restrict,
        array $extra = []
    ): array {
        $where = array_merge(["$table.is_deleted" => 0], $extra);
        $where[] = ["$table.$dateField" => ['>=', $from]];
        $where[] = ["$table.$dateField" => ['<=', $to]];
        if (!empty($restrict)) {
            $where[] = $restrict;
        }

        return $where;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month, int $entityId, bool $recursive = true): array
    {
        global $DB;

        [$from, $to] = self::monthBounds($year, $month);
        $prev        = (new DateTimeImmutable($from))->modify('-1 month');
        [$prevFrom, $prevTo] = self::monthBounds((int) $prev->format('Y'), (int) $prev->format('n'));

        // Atenção: o 4º parâmetro de getEntitiesRestrictCriteria() indica se a
        // TABELA possui a coluna is_recursive (tickets/problemas não possuem).
        // Para incluir subentidades, passamos a lista de IDs das entidades filhas.
        $entityScope = $recursive
            ? array_values(getSonsOf('glpi_entities', $entityId))
            : $entityId;

        $ticketRestrict  = getEntitiesRestrictCriteria('glpi_tickets', 'entities_id', $entityScope, false);
        $problemRestrict = getEntitiesRestrictCriteria('glpi_problems', 'entities_id', $entityScope, false);

        // ------------------------------------------------------------------
        // 1. Chamados ABERTOS no mês: status, tipo e origem (uma única consulta)
        // ------------------------------------------------------------------
        $rawStatus = [
            Ticket::INCOMING => 0,
            Ticket::ASSIGNED => 0,
            Ticket::PLANNED  => 0,
            Ticket::WAITING  => 0,
            Ticket::SOLVED   => 0,
            Ticket::CLOSED   => 0,
        ];
        $incidents  = 0;
        $demands    = 0;
        $originsRaw = [];
        $groups     = array_fill_keys(array_keys(self::ORIGIN_KEYWORDS), 0);
        $groups[self::ORIGIN_OTHER] = 0;
        $total = 0;

        $created = $DB->request([
            'SELECT'    => [
                'glpi_tickets.id',
                'glpi_tickets.status',
                'glpi_tickets.type',
                'glpi_requesttypes.name AS requesttype_name',
            ],
            'FROM'      => 'glpi_tickets',
            'LEFT JOIN' => [
                'glpi_requesttypes' => [
                    'ON' => [
                        'glpi_tickets'      => 'requesttypes_id',
                        'glpi_requesttypes' => 'id',
                    ],
                ],
            ],
            'WHERE'     => self::where('glpi_tickets', 'date', $from, $to, $ticketRestrict),
        ]);

        foreach ($created as $row) {
            $total++;

            $st = (int) $row['status'];
            $rawStatus[$st] = ($rawStatus[$st] ?? 0) + 1;

            $type = (int) $row['type'];
            if ($type === Ticket::INCIDENT_TYPE) {
                $incidents++;
            } elseif ($type === Ticket::DEMAND_TYPE) {
                $demands++;
            }

            $originName = trim((string) ($row['requesttype_name'] ?? ''));
            $originsRaw[$originName !== '' ? $originName : 'Não informado']
                = ($originsRaw[$originName !== '' ? $originName : 'Não informado'] ?? 0) + 1;
            $groups[self::originGroup($originName)]++;
        }
        arsort($originsRaw);
        // Status no formato do modelo: Planejado conta como "Atribuído";
        // Solucionado + Fechado contam como "Fechado".
        $status = [
            'novo'      => $rawStatus[Ticket::INCOMING],
            'atribuido' => $rawStatus[Ticket::ASSIGNED] + $rawStatus[Ticket::PLANNED],
            'pendente'  => $rawStatus[Ticket::WAITING],
            'fechado'   => $rawStatus[Ticket::SOLVED] + $rawStatus[Ticket::CLOSED],
        ];

        // Detalhado (5 estados) para os gráficos de volume.
        $statusDetail = [
            'Novo'           => $rawStatus[Ticket::INCOMING],
            'Em atendimento' => $rawStatus[Ticket::ASSIGNED] + $rawStatus[Ticket::PLANNED],
            'Pendente'       => $rawStatus[Ticket::WAITING],
            'Solucionado'    => $rawStatus[Ticket::SOLVED],
            'Fechado'        => $rawStatus[Ticket::CLOSED],
        ];

        // Origens: as 4 do modelo sempre aparecem; "Outras origens" só se houver.
        $origins = [];
        foreach ($groups as $label => $qty) {
            if ($label === self::ORIGIN_OTHER && $qty === 0) {
                continue;
            }
            $origins[] = [
                'label' => $label,
                'desc'  => self::ORIGIN_DESCRIPTIONS[$label],
                'qty'   => $qty,
            ];
        }

        // ------------------------------------------------------------------
        // 2. Mês anterior (comparativo) e problemas abertos no mês
        // ------------------------------------------------------------------
        $prevTotal = countElementsInTable(
            'glpi_tickets',
            self::where('glpi_tickets', 'date', $prevFrom, $prevTo, $ticketRestrict)
        );

        $problems = countElementsInTable(
            'glpi_problems',
            self::where('glpi_problems', 'date', $from, $to, $problemRestrict)
        );

        $deltaPct = $prevTotal > 0 ? (($total - $prevTotal) / $prevTotal) * 100 : null;

        // ------------------------------------------------------------------
        // 3. Chamados SOLUCIONADOS no mês: SLA, tempo médio e distribuição
        // ------------------------------------------------------------------
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);

        $solvedTotal  = 0;
        $withSla      = 0; // solucionados que possuem prazo (time_to_resolve)
        $withinSla    = 0; // desses, os solucionados dentro do prazo
        $totalSeconds = 0;

        $solved = $DB->request([
            'SELECT' => [
                'glpi_tickets.date',
                'glpi_tickets.solvedate',
                'glpi_tickets.time_to_resolve',
                'glpi_tickets.solve_delay_stat',
            ],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => self::where(
                'glpi_tickets',
                'solvedate',
                $from,
                $to,
                $ticketRestrict,
                ['glpi_tickets.status' => [Ticket::SOLVED, Ticket::CLOSED]]
            ),
        ]);

        foreach ($solved as $row) {
            $solvedTotal++;

            $solveTs = strtotime((string) $row['solvedate']);

            // solve_delay_stat já desconta o tempo em "Pendente"; se vier
            // zerado (chamados antigos/importados), cai para solvedate - date.
            $seconds = (int) $row['solve_delay_stat'];
            if ($seconds <= 0) {
                $seconds = max(0, $solveTs - strtotime((string) $row['date']));
            }
            $totalSeconds += $seconds;

            $days = $seconds / 86400;
            if ($days < 1) {
                $buckets['lt1']++;
            } elseif ($days <= 3) {
                $buckets['d13']++;
            } elseif ($days <= 7) {
                $buckets['d37']++;
            } else {
                $buckets['gt7']++;
            }

            // SLA: só entra na conta quem tem prazo definido.
            if (!empty($row['time_to_resolve'])) {
                $withSla++;
                if ($solveTs <= strtotime((string) $row['time_to_resolve'])) {
                    $withinSla++;
                }
            }
        }

        $slaPercent = $withSla > 0 ? (int) round(($withinSla / $withSla) * 100) : null;
        $avgSeconds = $solvedTotal > 0 ? (int) round($totalSeconds / $solvedTotal) : 0;

        $distribution = [];
        foreach (self::BUCKETS as $key => [$label, $desc]) {
            $distribution[] = ['label' => $label, 'desc' => $desc, 'qty' => $buckets[$key]];
        }

        // ------------------------------------------------------------------
        // 4. Top técnicos (chamados abertos no mês com técnico atribuído)
        // ------------------------------------------------------------------
        $topTechs = self::actorRanking(CommonITILActor::ASSIGN, $from, $to, $ticketRestrict, self::TOP_TECHS);

        // ------------------------------------------------------------------
        // 5. Métricas complementares. Cada uma é isolada: se uma consulta
        //    falhar, o relatório continua e o bloco aparece como "—".
        // ------------------------------------------------------------------
        $failed = [];
        $safe = static function (string $metric, callable $fn, $fallback) use (&$failed) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $failed[] = $metric;
                if (class_exists('Toolbox')) {
                    Toolbox::logInFile('analytics', sprintf("[%s] %s\n", $metric, $e->getMessage()));
                }

                return $fallback;
            }
        };

        $requesters = $safe(
            'solicitantes',
            fn () => self::actorRanking(CommonITILActor::REQUESTER, $from, $to, $ticketRestrict, self::TOP_RANKING),
            []
        );
        $categories = $safe(
            'categorias',
            fn () => self::categoryRanking($from, $to, $ticketRestrict),
            []
        );
        $groupsRank = $safe(
            'grupos',
            fn () => self::groupRanking($from, $to, $ticketRestrict),
            []
        );
        $extras = $safe(
            'prazos',
            fn () => self::solvedExtras($from, $to, $ticketRestrict),
            null
        );
        $workSeconds = $safe(
            'horas trabalhadas',
            fn () => self::workSeconds($from, $to, $ticketRestrict),
            null
        );
        $csat = $safe(
            'satisfação',
            fn () => self::csat($from, $to, $ticketRestrict),
            null
        );

        return [
            'period'        => ['month' => $month, 'year' => $year, 'from' => $from, 'to' => $to],
            'entity_id'     => $entityId,
            'recursive'     => $recursive,
            'status'        => $status,
            'total'         => $total,
            'prev_total'    => $prevTotal,
            'delta_pct'     => $deltaPct,
            'sla_percent'   => $slaPercent,
            'avg_formatted' => self::formatDuration($avgSeconds),
            'types'         => ['incident' => $incidents, 'demand' => $demands, 'problem' => $problems],
            'origins'       => $origins,
            'origins_raw'   => $originsRaw,
            'distribution'  => $distribution,
            'top_techs'     => $topTechs,
            'status_detail' => $statusDetail,
            'requesters'    => $requesters,
            'categories'    => $categories,
            'groups'        => $groupsRank,
            'sla_tto_percent'    => $extras['tto_percent'] ?? null,
            'avg_first_response' => isset($extras['avg_first_response'])
                ? self::formatDuration($extras['avg_first_response']) : null,
            'avg_pending'        => isset($extras['avg_pending'])
                ? self::formatDuration($extras['avg_pending']) : null,
            'work_hours'    => $workSeconds === null ? null : self::formatDuration($workSeconds),
            'csat'          => $csat,
            'failed'        => array_values(array_unique($failed)),
            'solved'        => [
                'total'      => $solvedTotal,
                'with_sla'   => $withSla,
                'within_sla' => $withinSla,
            ],
        ];
    }

    /**
     * Ranking de usuários por chamados abertos no mês, para um tipo de ator
     * (CommonITILActor::REQUESTER ou ::ASSIGN).
     *
     * @return list<array{name: string, total: int}>
     */
    private static function actorRanking(int $actorType, string $from, string $to, array $restrict, int $limit): array
    {
        global $DB;

        $perUser = [];
        $rows = $DB->request([
            'SELECT'     => [
                'glpi_tickets_users.users_id',
                'glpi_tickets.id AS tickets_id',
            ],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'tickets_id',
                        'glpi_tickets'       => 'id',
                        ['AND' => ['glpi_tickets_users.type' => $actorType]],
                    ],
                ],
            ],
            'WHERE'      => self::where('glpi_tickets', 'date', $from, $to, $restrict),
        ]);

        foreach ($rows as $row) {
            $uid = (int) $row['users_id'];
            if ($uid > 0) {
                $perUser[$uid][(int) $row['tickets_id']] = true;
            }
        }

        $counts = array_map('count', $perUser);
        arsort($counts);

        $ranking = [];
        foreach (array_slice($counts, 0, $limit, true) as $uid => $qty) {
            $ranking[] = ['name' => User::getFriendlyNameById($uid), 'total' => $qty];
        }

        return $ranking;
    }

    /**
     * Top categorias (glpi_itilcategories) dos chamados abertos no mês.
     *
     * @return list<array{name: string, total: int}>
     */
    private static function categoryRanking(string $from, string $to, array $restrict): array
    {
        global $DB;

        $counts = [];
        $rows = $DB->request([
            'SELECT'    => [
                'glpi_tickets.id',
                'glpi_itilcategories.completename AS category_name',
            ],
            'FROM'      => 'glpi_tickets',
            'LEFT JOIN' => [
                'glpi_itilcategories' => [
                    'ON' => [
                        'glpi_tickets'        => 'itilcategories_id',
                        'glpi_itilcategories' => 'id',
                    ],
                ],
            ],
            'WHERE'     => self::where('glpi_tickets', 'date', $from, $to, $restrict),
        ]);

        foreach ($rows as $row) {
            $name = trim((string) ($row['category_name'] ?? ''));
            $name = $name !== '' ? $name : 'Sem categoria';
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        arsort($counts);

        $ranking = [];
        foreach (array_slice($counts, 0, self::TOP_RANKING, true) as $name => $qty) {
            $ranking[] = ['name' => (string) $name, 'total' => $qty];
        }

        return $ranking;
    }

    /**
     * Chamados por grupo técnico atribuído (glpi_groups_tickets).
     *
     * @return list<array{name: string, total: int}>
     */
    private static function groupRanking(string $from, string $to, array $restrict): array
    {
        global $DB;

        $perGroup = [];
        $rows = $DB->request([
            'SELECT'     => [
                'glpi_tickets.id AS tickets_id',
                'glpi_groups.name AS group_name',
            ],
            'FROM'       => 'glpi_tickets',
            'INNER JOIN' => [
                'glpi_groups_tickets' => [
                    'ON' => [
                        'glpi_groups_tickets' => 'tickets_id',
                        'glpi_tickets'        => 'id',
                        ['AND' => ['glpi_groups_tickets.type' => CommonITILActor::ASSIGN]],
                    ],
                ],
                'glpi_groups'         => [
                    'ON' => [
                        'glpi_groups_tickets' => 'groups_id',
                        'glpi_groups'         => 'id',
                    ],
                ],
            ],
            'WHERE'      => self::where('glpi_tickets', 'date', $from, $to, $restrict),
        ]);

        foreach ($rows as $row) {
            $perGroup[(string) $row['group_name']][(int) $row['tickets_id']] = true;
        }

        $counts = array_map('count', $perGroup);
        arsort($counts);

        $ranking = [];
        foreach (array_slice($counts, 0, self::TOP_GROUPS, true) as $name => $qty) {
            $ranking[] = ['name' => (string) $name, 'total' => $qty];
        }

        return $ranking;
    }

    /**
     * Métricas extras dos chamados solucionados no mês: SLA de 1º atendimento
     * (time_to_own), tempo médio de 1º atendimento e tempo médio em Pendente.
     *
     * @return array{tto_percent: ?int, avg_first_response: ?int, avg_pending: ?int}
     */
    private static function solvedExtras(string $from, string $to, array $restrict): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [
                'glpi_tickets.time_to_own',
                'glpi_tickets.takeintoaccountdate',
                'glpi_tickets.takeintoaccount_delay_stat',
                'glpi_tickets.waiting_duration',
            ],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => self::where(
                'glpi_tickets',
                'solvedate',
                $from,
                $to,
                $restrict,
                ['glpi_tickets.status' => [Ticket::SOLVED, Ticket::CLOSED]]
            ),
        ]);

        $count = $ttoWith = $ttoWithin = $frCount = $frSum = $pendingSum = 0;

        foreach ($rows as $row) {
            $count++;
            $pendingSum += (int) $row['waiting_duration'];

            if (!empty($row['takeintoaccountdate'])) {
                $frCount++;
                $frSum += (int) $row['takeintoaccount_delay_stat'];

                if (!empty($row['time_to_own'])) {
                    $ttoWith++;
                    if (strtotime((string) $row['takeintoaccountdate']) <= strtotime((string) $row['time_to_own'])) {
                        $ttoWithin++;
                    }
                }
            }
        }

        return [
            'tto_percent'        => $ttoWith > 0 ? (int) round(($ttoWithin / $ttoWith) * 100) : null,
            'avg_first_response' => $frCount > 0 ? (int) round($frSum / $frCount) : null,
            'avg_pending'        => $count > 0 ? (int) round($pendingSum / $count) : null,
        ];
    }

    /**
     * Soma do tempo de ação (segundos) das tarefas registradas no mês.
     */
    private static function workSeconds(string $from, string $to, array $restrict): int
    {
        global $DB;

        $where = [
            'glpi_tickets.is_deleted' => 0,
            ['glpi_tickettasks.date' => ['>=', $from]],
            ['glpi_tickettasks.date' => ['<=', $to]],
        ];
        if (!empty($restrict)) {
            $where[] = $restrict;
        }

        $rows = $DB->request([
            'SELECT'     => ['glpi_tickettasks.actiontime'],
            'FROM'       => 'glpi_tickettasks',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets'     => 'id',
                    ],
                ],
            ],
            'WHERE'      => $where,
        ]);

        $seconds = 0;
        foreach ($rows as $row) {
            $seconds += (int) $row['actiontime'];
        }

        return $seconds;
    }

    /**
     * Satisfação (CSAT): pesquisas respondidas no mês.
     *
     * @return array{answered: int, avg: ?float, satisfied_pct: ?int}
     */
    private static function csat(string $from, string $to, array $restrict): array
    {
        global $DB;

        $where = [
            'glpi_tickets.is_deleted' => 0,
            ['glpi_ticketsatisfactions.date_answered' => ['>=', $from]],
            ['glpi_ticketsatisfactions.date_answered' => ['<=', $to]],
            ['NOT' => ['glpi_ticketsatisfactions.satisfaction' => null]],
        ];
        if (!empty($restrict)) {
            $where[] = $restrict;
        }

        $rows = $DB->request([
            'SELECT'     => ['glpi_ticketsatisfactions.satisfaction'],
            'FROM'       => 'glpi_ticketsatisfactions',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_ticketsatisfactions' => 'tickets_id',
                        'glpi_tickets'             => 'id',
                    ],
                ],
            ],
            'WHERE'      => $where,
        ]);

        $answered = $sum = $satisfied = 0;
        foreach ($rows as $row) {
            $answered++;
            $sum += (int) $row['satisfaction'];
            if ((int) $row['satisfaction'] >= 4) {
                $satisfied++;
            }
        }

        return [
            'answered'      => $answered,
            'avg'           => $answered > 0 ? round($sum / $answered, 1) : null,
            'satisfied_pct' => $answered > 0 ? (int) round(($satisfied / $answered) * 100) : null,
        ];
    }

    /**
     * Top técnicos completado com "Reservado para expansão" até o mínimo de
     * linhas do modelo.
     *
     * @return list<array{name: string, total: int}>
     */
    public static function paddedTechs(array $data): array
    {
        $techs = $data['top_techs'];
        while (count($techs) < self::MIN_TECH_ROWS) {
            $techs[] = ['name' => 'Reservado para expansão', 'total' => 0];
        }

        return $techs;
    }

    /**
     * Segundos -> "H:MM" (horas podem passar de 24).
     */
    public static function formatDuration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Neutraliza injeção de fórmulas em planilhas (valores que começam com
     * = + - @) em campos de texto vindos do banco.
     */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /**
     * Linhas para exportação CSV.
     *
     * @return list<list<string|int>>
     */
    public static function toCsvRows(array $data, string $clientName, array $monthsMap): array
    {
        $rows = [];

        $rows[] = ['Relatório Mensal de Service Desk'];
        $rows[] = [];
        $rows[] = ['IDENTIFICAÇÃO'];
        $rows[] = ['Cliente / Empresa', self::csvSafe($clientName)];
        $rows[] = ['Mês de Referência', $monthsMap[$data['period']['month']]];
        $rows[] = ['Ano', $data['period']['year']];
        $rows[] = [];

        $rows[] = ['STATUS DOS CHAMADOS (FIM DO MÊS)'];
        $rows[] = ['Novo', $data['status']['novo']];
        $rows[] = ['Atribuído', $data['status']['atribuido']];
        $rows[] = ['Pendente', $data['status']['pendente']];
        $rows[] = ['Fechado', $data['status']['fechado']];
        $rows[] = ['Total no mês', $data['total']];
        $rows[] = [];

        $rows[] = ['COMPARATIVO E SLA'];
        $rows[] = ['Total no mês anterior', $data['prev_total']];
        $rows[] = [
            'Variação vs. mês anterior (%)',
            $data['delta_pct'] === null ? '' : number_format($data['delta_pct'], 1, ',', ''),
        ];
        $rows[] = ['% dentro do prazo (SLA)', $data['sla_percent'] ?? ''];
        $rows[] = ['Tempo médio de solução (h:mm)', $data['avg_formatted']];
        $rows[] = [];

        $rows[] = ['CHAMADOS POR ORIGEM'];
        foreach ($data['origins'] as $o) {
            $rows[] = [$o['label'], $o['qty']];
        }
        $rows[] = [];

        $rows[] = ['CHAMADOS POR TIPO'];
        $rows[] = ['Incidente', $data['types']['incident']];
        $rows[] = ['Requisição', $data['types']['demand']];
        $rows[] = ['Problema', $data['types']['problem']];
        $rows[] = [];

        $rows[] = ['TEMPO DE SOLUÇÃO (DISTRIBUIÇÃO)'];
        foreach ($data['distribution'] as $d) {
            $rows[] = [$d['label'], $d['qty']];
        }
        $rows[] = [];

        $rows[] = ['TOP TÉCNICOS'];
        foreach (self::paddedTechs($data) as $tech) {
            $rows[] = [self::csvSafe((string) $tech['name']), $tech['total']];
        }
        $rows[] = [];

        $rows[] = ['VOLUME POR STATUS (DETALHADO)'];
        foreach ($data['status_detail'] as $label => $qty) {
            $rows[] = [$label, $qty];
        }
        $rows[] = [];

        $rows[] = ['CATEGORIAS MAIS RECORRENTES (TOP 10)'];
        foreach ($data['categories'] as $c) {
            $rows[] = [self::csvSafe($c['name']), $c['total']];
        }
        $rows[] = [];

        $rows[] = ['TOP 10 SOLICITANTES'];
        foreach ($data['requesters'] as $r) {
            $rows[] = [self::csvSafe((string) $r['name']), $r['total']];
        }
        $rows[] = [];

        $rows[] = ['PRAZOS E DESEMPENHO'];
        $rows[] = ['% solução dentro do prazo (SLA)', $data['sla_percent'] ?? ''];
        $rows[] = ['% 1º atendimento dentro do prazo', $data['sla_tto_percent'] ?? ''];
        $rows[] = ['Tempo médio de solução (h:mm)', $data['avg_formatted']];
        $rows[] = ['Tempo médio de 1º atendimento (h:mm)', $data['avg_first_response'] ?? ''];
        $rows[] = ['Tempo médio em Pendente (h:mm)', $data['avg_pending'] ?? ''];
        $rows[] = [];

        $rows[] = ['PRODUTIVIDADE'];
        foreach ($data['groups'] as $g) {
            $rows[] = ['Grupo: ' . self::csvSafe((string) $g['name']), $g['total']];
        }
        $rows[] = ['Horas trabalhadas (h:mm)', $data['work_hours'] ?? ''];
        $rows[] = [];

        $rows[] = ['SATISFAÇÃO (CSAT)'];
        $rows[] = ['Pesquisas respondidas', $data['csat']['answered'] ?? ''];
        $rows[] = ['Nota média (0-5)', isset($data['csat']['avg']) ? str_replace('.', ',', (string) $data['csat']['avg']) : ''];
        $rows[] = ['% satisfeitos (nota 4 ou 5)', $data['csat']['satisfied_pct'] ?? ''];

        return $rows;
    }
}
