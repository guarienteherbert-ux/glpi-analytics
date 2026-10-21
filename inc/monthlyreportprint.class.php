<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Versão de impressão / PDF do Relatório Mensal
 * -------------------------------------------------------------------------
 *
 * Gera uma página HTML independente do layout do GLPI (sem menu lateral,
 * cabeçalho ou rodapé do sistema), pensada para "Imprimir > Salvar como PDF"
 * em A4:
 *   - página 1: modelo do relatório (mesmas seções do Word);
 *   - página 2: indicadores complementares com mini gráficos;
 *   - logos no cabeçalho (empresa à esquerda, cliente à direita), rodapé com
 *     data e numeração de páginas.
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

class PluginAnalyticsMonthlyReportPrint
{
    private static function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function dash($value): string
    {
        return ($value === null || $value === '') ? '—' : (string) $value;
    }

    /** Linha de cabeçalho cinza + linha de valores (tabelas de N colunas). */
    private static function grid(array $labels, array $values, bool $leftAlign = false): string
    {
        $th = '';
        foreach ($labels as $label) {
            $th .= '<th>' . self::h($label) . '</th>';
        }
        $td = '';
        foreach ($values as $value) {
            $td .= '<td class="v' . ($leftAlign ? ' l' : '') . '">' . self::h($value) . '</td>';
        }

        return '<table><tr>' . $th . '</tr><tr>' . $td . '</tr></table>';
    }

    /** Blocos "rótulo | Quantidade" seguidos de "descrição | valor". */
    private static function labelQty(array $rows): string
    {
        $html = '<table class="lq"><colgroup><col><col class="qc"></colgroup>';
        foreach ($rows as $r) {
            $html .= '<tr><th>' . self::h($r['label']) . '</th><th class="r">Quantidade</th></tr>'
                . '<tr><td>' . self::h($r['desc']) . '</td><td class="v">' . self::h($r['qty']) . '</td></tr>';
        }

        return $html . '</table>';
    }

    /** Lista compacta: um único cabeçalho e uma linha por item. */
    private static function ranking(string $head, array $rows): string
    {
        $html = '<table class="lq"><colgroup><col><col class="qc"></colgroup>'
            . '<tr><th>' . self::h($head) . '</th><th class="r">Quantidade</th></tr>';
        foreach ($rows as $r) {
            $html .= '<tr><td>' . self::h($r['name']) . '</td><td class="v">' . self::h($r['total']) . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * Mini gráfico de barras horizontais (tabela, para renderizar igual em
     * qualquer navegador).
     *
     * @param array<string, int|float> $items
     */
    private static function bars(array $items): string
    {
        if (empty($items)) {
            return '<div class="empty">Sem dados no período</div>';
        }

        $max  = max(1, (int) max($items));
        $html = '<table class="bars">';
        foreach ($items as $label => $qty) {
            $qty = (int) $qty;
            $pct = $qty > 0 ? max(2, (int) round($qty / $max * 100)) : 0;
            $html .= '<tr><td class="bl">' . self::h(mb_strimwidth((string) $label, 0, 38, '…')) . '</td>'
                . '<td class="bt"><div class="bf" style="width:' . $pct . '%"></div></td>'
                . '<td class="bv">' . $qty . '</td></tr>';
        }

        return $html . '</table>';
    }

    private static function section(string $title, string $body): string
    {
        return '<div class="block"><div class="sec">' . self::h($title) . '</div>' . $body . '</div>';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(
        array $data,
        string $clientName,
        string $monthName,
        ?string $companyLogo,
        ?string $clientLogo,
        string $generatedBy
    ): string {
        $year  = (int) $data['period']['year'];
        $title = sprintf('Relatório Mensal de Service Desk - %s - %s %d', $clientName, $monthName, $year);

        // Texto do rodapé (dentro de uma string CSS: sem aspas, barras ou < >)
        $footer = sprintf('Gerado em %s por %s — GLPI Service Desk Analytics', date('d/m/Y H:i'), $generatedBy);
        $footer = addcslashes((string) preg_replace('/[<>\r\n]/', '', $footer), "\"\\");

        // ---------------- Página 1: modelo ----------------
        $originBars = [];
        foreach ($data['origins'] as $o) {
            $originBars[$o['label']] = $o['qty'];
        }

        $page1  = self::section('IDENTIFICAÇÃO', self::grid(
            ['Cliente / Empresa', 'Mês de Referência', 'Ano'],
            [$clientName, $monthName, $year],
            true
        ));
        $page1 .= self::section('STATUS DOS CHAMADOS (FIM DO MÊS)', self::grid(
            ['Novo', 'Atribuído', 'Pendente', 'Fechado', 'Total no mês'],
            [
                $data['status']['novo'], $data['status']['atribuido'], $data['status']['pendente'],
                $data['status']['fechado'], $data['total'],
            ]
        ));
        $page1 .= self::section('COMPARATIVO E SLA', self::grid(
            ['Total no mês anterior', '% dentro do prazo (SLA)', 'Tempo médio de solução'],
            [$data['prev_total'], self::dash($data['sla_percent']), $data['avg_formatted']]
        ));
        $page1 .= self::section('CHAMADOS POR ORIGEM', self::labelQty($data['origins']));
        $page1 .= self::section('CHAMADOS POR TIPO', self::grid(
            ['Incidente', 'Requisição', 'Problema'],
            [$data['types']['incident'], $data['types']['demand'], $data['types']['problem']]
        ));
        $page1 .= self::section('TEMPO DE SOLUÇÃO (DISTRIBUIÇÃO)', self::labelQty($data['distribution']));
        $page1 .= self::section('TOP TÉCNICOS', self::ranking(
            'Nome do técnico / Analista',
            PluginAnalyticsMonthlyReportData::paddedTechs($data)
        ));

        // ---------------- Página 2: complementares ----------------
        $csat = $data['csat'] ?? null;

        $volume = '<table class="cols"><tr>'
            . '<td><div class="ct">Por status</div>' . self::bars($data['status_detail']) . '</td>'
            . '<td><div class="ct">Por tipo</div>' . self::bars([
                'Incidente'  => $data['types']['incident'],
                'Requisição' => $data['types']['demand'],
                'Problema'   => $data['types']['problem'],
            ]) . '</td>'
            . '<td><div class="ct">Por origem</div>' . self::bars($originBars) . '</td>'
            . '</tr></table>';

        $rankings = '<table class="cols two"><tr>'
            . '<td><div class="sec">CATEGORIAS MAIS RECORRENTES (TOP 10)</div>'
            . self::bars(array_column($data['categories'], 'total', 'name')) . '</td>'
            . '<td><div class="sec">TOP 10 SOLICITANTES</div>'
            . self::bars(array_column($data['requesters'], 'total', 'name')) . '</td>'
            . '</tr></table>';

        $productivity = '<table class="cols two"><tr>'
            . '<td><div class="ct">Chamados por grupo</div>'
            . self::bars(array_column($data['groups'], 'total', 'name'))
            . '<div class="hours">Horas trabalhadas (tempo de ação, h:mm): <b>'
            . self::h(self::dash($data['work_hours'])) . '</b></div></td>'
            . '<td>' . self::grid(
                ['Respondidas', 'Nota média (0–5)', '% satisfeitos (4–5)'],
                [
                    self::dash($csat['answered'] ?? null),
                    isset($csat['avg']) ? str_replace('.', ',', (string) $csat['avg']) : '—',
                    self::dash($csat['satisfied_pct'] ?? null),
                ]
            ) . '</td>'
            . '</tr></table>';

        $page2  = self::section('VOLUME E DISTRIBUIÇÃO DE CHAMADOS', $volume);
        $page2 .= '<div class="block">' . $rankings . '</div>';
        $page2 .= self::section('PRAZOS E DESEMPENHO', self::grid(
            ['SLA solução (%)', 'SLA 1º atend. (%)', 'TMA solução', 'TMA 1º atend.', 'Tempo em Pendente'],
            [
                self::dash($data['sla_percent']), self::dash($data['sla_tto_percent']), $data['avg_formatted'],
                self::dash($data['avg_first_response']), self::dash($data['avg_pending']),
            ]
        ));
        $page2 .= '<div class="block"><div class="sec">PRODUTIVIDADE DA EQUIPE E SATISFAÇÃO (CSAT)</div>'
            . $productivity . '</div>';

        $logoLeft  = $companyLogo !== null
            ? '<img class="logo" src="' . self::h($companyLogo) . '" alt="Logo da empresa">'
            : '';
        $logoRight = $clientLogo !== null
            ? '<img class="logo" src="' . self::h($clientLogo) . '" alt="Logo do cliente">'
            : '';

        $css = <<<CSS
@page {
  size: A4;
  margin: 12mm 12mm 16mm 12mm;
  @bottom-left  { content: "{$footer}"; font: 7.5pt Arial, sans-serif; color: #666; }
  @bottom-right { content: "Página " counter(page) " de " counter(pages); font: 7.5pt Arial, sans-serif; color: #666; }
}
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; font-family: Calibri, Carlito, "Segoe UI", Arial, sans-serif; font-size: 10pt; color: #111; background: #e9ecef; }
.toolbar { max-width: 210mm; margin: 12px auto; display: flex; gap: 8px; align-items: center; font: 13px Arial, sans-serif; color: #444; }
.toolbar button { padding: 7px 14px; border: 0; border-radius: 4px; background: #44546A; color: #fff; font-size: 13px; cursor: pointer; }
.toolbar button.alt { background: #fff; color: #333; border: 1px solid #bbb; }
.sheet { width: 210mm; margin: 0 auto 24px; padding: 12mm; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.25); }
.head { display: table; width: 100%; margin-bottom: 3mm; }
.head .t { display: table-cell; vertical-align: middle; text-align: center; }
.head .lc { display: table-cell; vertical-align: middle; text-align: left; width: 48mm; }
.head .lr { display: table-cell; vertical-align: middle; text-align: right; width: 48mm; }
h1 { margin: 0; font-size: 16pt; }
h2 { margin: 0 0 3mm; font-size: 14pt; }
.sub { margin-top: 1mm; font-size: 9pt; color: #555; }
.logo { max-height: 16mm; max-width: 46mm; }
.block { break-inside: avoid; page-break-inside: avoid; margin-bottom: 3mm; }
.p2 { margin-top: 5mm; padding-top: 3mm; border-top: 0.4pt solid #ccc; }
.sec { background: #44546A; color: #fff; font-weight: 700; font-size: 11pt; padding: 1.2mm 2.5mm; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th { background: #A5A5A5; color: #fff; text-align: left; font-size: 9.5pt; padding: .8mm 2.5mm; font-weight: 700; }
th.r { text-align: right; }
td { border: .4pt solid #222; padding: .8mm 2.5mm; font-size: 9pt; }
td.v { color: #C00000; font-weight: 700; text-align: center; font-size: 10pt; }
td.v.l { text-align: left; }
col.qc { width: 26mm; }
.lq td:first-child { font-size: 8.5pt; }
table.cols { margin-top: 2mm; }
table.cols > tbody > tr > td, table.cols > tr > td { border: 0; padding: 0 3mm 0 0; vertical-align: top; }
table.cols > tbody > tr > td:last-child, table.cols > tr > td:last-child { padding-right: 0; }
.ct { background: #A5A5A5; color: #fff; font-weight: 700; font-size: 9.5pt; padding: 1mm 2.5mm; margin-bottom: 1.5mm; }
table.bars td { border: 0; padding: .6mm 1mm; font-size: 8.5pt; }
td.bl { width: 42%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
td.bt { padding: .6mm 1.5mm; }
.bf { height: 3mm; background: #44546A; }
td.bv { width: 9mm; text-align: right; color: #C00000; font-weight: 700; }
.empty { font-size: 8.5pt; color: #777; padding: 1mm; }
.hours { margin-top: 3mm; font-size: 9pt; }
@media print {
  body { background: #fff; }
  .toolbar { display: none !important; }
  .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
}
CSS;

        $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $h($title) . '</title><style>' . $css . '</style></head><body>'
            . '<div class="toolbar"><button type="button" onclick="window.print()">Imprimir / Salvar como PDF</button>'
            . '<button type="button" class="alt" onclick="window.close()">Fechar</button>'
            . '<span>Se o diálogo não abrir sozinho, use Ctrl+P. Escolha "Salvar como PDF", papel A4.</span></div>'
            . '<div class="sheet">'
            . '<div class="head"><div class="lc">' . $logoLeft . '</div>'
            . '<div class="t"><h1>Relatório Mensal de Service Desk</h1>'
            . '<div class="sub">' . $h($clientName) . ' — ' . $h($monthName) . ' de ' . $year . '</div></div>'
            . '<div class="lr">' . $logoRight . '</div></div>'
            . $page1
            . '<div class="p2"><h2>Indicadores Complementares</h2>' . $page2 . '</div>'
            . '</div>'
            . '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>'
            . '</body></html>';
    }
}
