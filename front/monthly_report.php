<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Interface do Relatório Mensal
 * -------------------------------------------------------------------------
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

include('../../../inc/includes.php');

Session::checkRight('ticket', READ);

global $CFG_GLPI;

$selfUrl = Plugin::getWebDir('analytics') . '/front/monthly_report.php';

// --- Envio / remoção de logos (POST) ----------------------------------------
// Só quem pode alterar a configuração do GLPI. O token CSRF é validado pelo
// próprio GLPI (o formulário usa Html::closeForm()).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('config', UPDATE);

    $postEntity = (int) ($_POST['entities_id'] ?? 0);
    if (!Session::haveAccessToEntity($postEntity)) {
        $postEntity = (int) $_SESSION['glpiactive_entity'];
    }
    $kind = (string) ($_POST['logo_kind'] ?? '');

    if (!PluginAnalyticsLogo::isValidKind($kind)) {
        [$ok, $msg] = [false, 'Tipo de logo inválido.'];
    } elseif (isset($_POST['remove_logo'])) {
        $ok  = PluginAnalyticsLogo::remove($kind, $postEntity);
        $msg = $ok ? 'Logo removida.' : 'Não foi possível remover a logo.';
    } else {
        [$ok, $msg] = PluginAnalyticsLogo::save($kind, $postEntity, $_FILES['logo'] ?? []);
    }

    Session::addMessageAfterRedirect($msg, false, $ok ? INFO : ERROR);

    $back = [
        'month'       => (int) ($_POST['month'] ?? date('n')),
        'year'        => (int) ($_POST['year'] ?? date('Y')),
        'entities_id' => $postEntity,
    ] + (!empty($_POST['recursive']) ? ['recursive' => 1] : []);
    Html::redirect($selfUrl . '?' . http_build_query($back));
}

$monthsMap = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];

// --- Parâmetros (GET) validados ------------------------------------------
$currentYear = (int) date('Y');

$month = (int) filter_var(
    $_GET['month'] ?? date('n'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 12, 'default' => (int) date('n')]]
);
$year = (int) filter_var(
    $_GET['year'] ?? $currentYear,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 2000, 'max_range' => $currentYear + 1, 'default' => $currentYear]]
);

// Entidade: só aceita entidades às quais o usuário realmente tem acesso.
$entityId = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity']);
if (!Session::haveAccessToEntity($entityId)) {
    $entityId = (int) $_SESSION['glpiactive_entity'];
}

// Checkbox desmarcado não é enviado: só assume "true" na primeira visita.
$recursive = isset($_GET['entities_id']) ? !empty($_GET['recursive']) : true;

$entity     = new Entity();
$clientName = $entity->getFromDB($entityId)
    ? (string) $entity->fields['name']
    : 'Entidade #' . $entityId;

// --- Dados ----------------------------------------------------------------
$data = PluginAnalyticsMonthlyReportData::build($year, $month, $entityId, $recursive);

$query   = ['month' => $month, 'year' => $year, 'entities_id' => $entityId]
    + ($recursive ? ['recursive' => 1] : []);

// --- Logos (empresa e cliente) ---------------------------------------------
$companyLogoFile = PluginAnalyticsLogo::find(PluginAnalyticsLogo::COMPANY);
$clientLogoFile  = PluginAnalyticsLogo::find(PluginAnalyticsLogo::CLIENT, $entityId);
$companyLogo     = PluginAnalyticsLogo::dataUri(PluginAnalyticsLogo::COMPANY);
$clientLogo      = PluginAnalyticsLogo::dataUri(PluginAnalyticsLogo::CLIENT, $entityId);

// --- Exportações (antes de qualquer saída HTML) ----------------------------
$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'docx') {
    $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $clientName), '-');
    $slug = $slug !== '' ? $slug : 'entidade';

    if ($export === 'csv') {
        $filename = sprintf('relatorio-mensal-%s-%04d-%02d.csv', $slug, $year, $month);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF"; // BOM para o Excel reconhecer UTF-8
        $out = fopen('php://output', 'w');
        foreach (PluginAnalyticsMonthlyReportData::toCsvRows($data, $clientName, $monthsMap) as $csvRow) {
            fputcsv($out, $csvRow, ';', '"', '\\');
        }
        fclose($out);
        exit;
    }

    $binary   = PluginAnalyticsMonthlyReportDocx::build($data, $clientName, $monthsMap[$month], $companyLogoFile, $clientLogoFile);
    $ascii    = sprintf('relatorio-mensal-%s-%04d-%02d.docx', $slug, $year, $month);
    $niceName = sprintf('Relatório Mensal de Service Desk - %s - %s %d.docx', $clientName, $monthsMap[$month], $year);

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($niceName));
    header('Content-Length: ' . strlen($binary));
    echo $binary;
    exit;
}

// --- Versão de impressão / PDF (página A4 independente do layout do GLPI) ---
if (($_GET['view'] ?? '') === 'print') {
    header('Content-Type: text/html; charset=UTF-8');
    echo PluginAnalyticsMonthlyReportPrint::render(
        $data,
        $clientName,
        $monthsMap[$month],
        $companyLogo,
        $clientLogo,
        (string) User::getFriendlyNameById((int) Session::getLoginUserID())
    );
    exit;
}

$canManageLogos = Session::haveRight('config', UPDATE);

// --- Helpers de exibição ---------------------------------------------------
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Mini gráfico de barras horizontais: rótulo | barra | valor. */
$bars = static function (array $items) use ($h): void {
    if (empty($items)) {
        echo '<div class="text-muted small">Sem dados no período</div>';
        return;
    }
    $max = max(1, (int) max($items));
    foreach ($items as $label => $qty) {
        $qty = (int) $qty;
        $pct = $qty > 0 ? max(2, (int) round($qty / $max * 100)) : 0;
        echo '<div class="analytics-bar-row">'
            . '<span class="analytics-bar-label" title="' . $h($label) . '">' . $h($label) . '</span>'
            . '<span class="analytics-bar-track"><span class="analytics-bar-fill" style="width:' . $pct . '%"></span></span>'
            . '<span class="analytics-bar-value">' . $qty . '</span>'
            . '</div>';
    }
};

$dash = static fn ($v): string => ($v === null || $v === '') ? '—' : (string) $v;

$originBars = [];
foreach ($data['origins'] as $o) {
    $originBars[$o['label']] = $o['qty'];
}
$rawOrigins = [];
foreach ($data['origins_raw'] as $name => $qty) {
    $rawOrigins[] = $name . ' (' . $qty . ')';
}

Html::header(
    PluginAnalyticsMonthlyReport::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginAnalyticsMonthlyReport'
);
?>

<div class="container-fluid mt-3 mb-5">

    <!-- Filtros -->
    <div class="card mb-4 d-print-none">
        <div class="card-body">
            <form method="get" action="<?= $h($selfUrl) ?>" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Entidade / Cliente</label>
                    <?php
                    Entity::dropdown([
                        'name'                => 'entities_id',
                        'value'               => $entityId,
                        'display_emptychoice' => false,
                        'width'               => '100%',
                    ]);
                    ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="recursive" value="1"
                               id="analytics_recursive" <?= $recursive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="analytics_recursive">Incluir subentidades</label>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mês</label>
                    <?php Dropdown::showFromArray('month', $monthsMap, ['value' => $month, 'width' => '100%']); ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ano</label>
                    <?php
                    Dropdown::showNumber('year', [
                        'value' => $year,
                        'min'   => $currentYear - 10,
                        'max'   => $currentYear + 1,
                        'width' => '100%',
                    ]);
                    ?>
                </div>
                <div class="col-md-5 d-flex flex-wrap gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-filter"></i> Gerar relatório
                    </button>
                    <a class="btn btn-outline-primary"
                       href="<?= $h($selfUrl . '?' . http_build_query($query + ['export' => 'docx'])) ?>">
                        <i class="ti ti-file-type-doc"></i> Word
                    </a>
                    <a class="btn btn-outline-secondary"
                       href="<?= $h($selfUrl . '?' . http_build_query($query + ['export' => 'csv'])) ?>">
                        <i class="ti ti-file-spreadsheet"></i> CSV
                    </a>
                    <a class="btn btn-dark" target="_blank" rel="noopener"
                       href="<?= $h($selfUrl . '?' . http_build_query($query + ['view' => 'print'])) ?>">
                        <i class="ti ti-printer"></i> PDF / Imprimir
                    </a>
                </div>
            </form>

            <?php if ($canManageLogos): ?>
                <details class="mt-3">
                    <summary class="text-muted">Logos do relatório (PDF / impressão)</summary>
                    <div class="row g-3 mt-1">
                        <?php
                        $logoPanels = [
                            PluginAnalyticsLogo::COMPANY => [
                                'title' => 'Logo da empresa',
                                'hint'  => 'Única, aparece à esquerda do cabeçalho em todos os relatórios.',
                                'own'   => PluginAnalyticsLogo::hasOwn(PluginAnalyticsLogo::COMPANY),
                                'uri'   => $companyLogo,
                            ],
                            PluginAnalyticsLogo::CLIENT  => [
                                'title' => 'Logo do cliente — ' . $clientName,
                                'hint'  => 'Uma por entidade, aparece à direita do cabeçalho. '
                                    . 'Sem logo própria, usa a da entidade-pai mais próxima.',
                                'own'   => PluginAnalyticsLogo::hasOwn(PluginAnalyticsLogo::CLIENT, $entityId),
                                'uri'   => $clientLogo,
                            ],
                        ];
                        foreach ($logoPanels as $kind => $panel):
                        ?>
                            <div class="col-md-6">
                                <form method="post" enctype="multipart/form-data" action="<?= $h($selfUrl) ?>">
                                    <input type="hidden" name="logo_kind" value="<?= $h($kind) ?>">
                                    <input type="hidden" name="entities_id" value="<?= (int) $entityId ?>">
                                    <input type="hidden" name="month" value="<?= (int) $month ?>">
                                    <input type="hidden" name="year" value="<?= (int) $year ?>">
                                    <?php if ($recursive): ?><input type="hidden" name="recursive" value="1"><?php endif; ?>

                                    <label class="form-label fw-bold"><?= $h($panel['title']) ?></label>
                                    <div class="small text-muted mb-2"><?= $h($panel['hint']) ?></div>

                                    <?php if ($kind === PluginAnalyticsLogo::CLIENT && $entityId === 0): ?>
                                        <div class="alert alert-info py-2">
                                            A entidade raiz representa a própria empresa. Selecione a entidade de um
                                            cliente para definir a logo dele.
                                        </div>
                                    <?php else: ?>
                                        <?php if ($panel['uri'] !== null): ?>
                                            <div class="mb-2">
                                                <img src="<?= $h($panel['uri']) ?>" alt=""
                                                     style="max-height:48px; max-width:180px; background:#fff; padding:4px; border:1px solid rgba(128,128,128,.4);">
                                                <?php if (!$panel['own']): ?>
                                                    <span class="small text-muted ms-2">(herdada de uma entidade-pai)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="file" name="logo" accept="image/png,image/jpeg"
                                                   class="form-control form-control-sm">
                                            <button type="submit" name="upload_logo" value="1"
                                                    class="btn btn-sm btn-primary">Enviar</button>
                                            <?php if ($panel['own']): ?>
                                                <button type="submit" name="remove_logo" value="1"
                                                        class="btn btn-sm btn-outline-danger">Remover</button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted mt-1">PNG ou JPEG, até 1 MB. Fundo transparente (PNG) recomendado.</div>
                                    <?php endif; ?>
                                    <?php Html::closeForm(); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($data['failed'])): ?>
        <div class="alert alert-warning d-print-none">
            Alguns indicadores não puderam ser calculados e aparecem como "—":
            <strong><?= $h(implode(', ', $data['failed'])) ?></strong>.
            Detalhes em <code>files/_log/analytics.log</code>.
        </div>
    <?php endif; ?>

    <!-- Relatório -->
    <div class="card" id="report-printable">
        <div class="card-body">

            <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4">
                <div style="width: 180px">
                    <?php if ($companyLogo !== null): ?>
                        <img src="<?= $h($companyLogo) ?>" alt="" style="max-height:56px; max-width:170px;">
                    <?php endif; ?>
                </div>
                <div class="text-center flex-grow-1">
                    <h2 class="fw-bold mb-1">Relatório Mensal de Service Desk</h2>
                    <p class="text-muted mb-0">GLPI Service Desk Analytics — Visão Gerencial</p>
                </div>
                <div style="width: 180px" class="text-end">
                    <?php if ($clientLogo !== null): ?>
                        <img src="<?= $h($clientLogo) ?>" alt="" style="max-height:56px; max-width:170px;">
                    <?php endif; ?>
                </div>
            </div>

            <h5 class="analytics-section">IDENTIFICAÇÃO</h5>
            <table class="table table-bordered mb-4">
                <thead>
                    <tr><th>Cliente / Empresa</th><th>Mês de Referência</th><th>Ano</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold"><?= $h($clientName) ?><?= $recursive ? ' <small class="text-muted">(com subentidades)</small>' : '' ?></td>
                        <td><?= $h($monthsMap[$month]) ?></td>
                        <td><?= $h($year) ?></td>
                    </tr>
                </tbody>
            </table>

            <h5 class="analytics-section">STATUS DOS CHAMADOS (FIM DO MÊS)</h5>
            <table class="table table-bordered mb-4 text-center">
                <thead>
                    <tr><th>Novo</th><th>Atribuído</th><th>Pendente</th><th>Fechado</th><th>Total no mês</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= (int) $data['status']['novo'] ?></td>
                        <td><?= (int) $data['status']['atribuido'] ?></td>
                        <td><?= (int) $data['status']['pendente'] ?></td>
                        <td><?= (int) $data['status']['fechado'] ?></td>
                        <td class="fw-bold"><?= (int) $data['total'] ?></td>
                    </tr>
                </tbody>
            </table>
            <h5 class="analytics-section">COMPARATIVO E SLA</h5>
            <table class="table table-bordered mb-4 text-center">
                <thead>
                    <tr><th>Total no mês anterior</th><th>% dentro do prazo (SLA)</th><th>Tempo médio de solução (h:mm)</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= (int) $data['prev_total'] ?></td>
                        <td class="fw-bold"><?= $h($dash($data['sla_percent'])) ?></td>
                        <td><?= $h($data['avg_formatted']) ?></td>
                    </tr>
                </tbody>
            </table>
            <h5 class="analytics-section">CHAMADOS POR ORIGEM</h5>
            <table class="table table-bordered mb-1">
                <tbody>
                    <?php foreach ($data['origins'] as $o): ?>
                        <tr>
                            <td class="fw-bold" style="width: 34%"><?= $h($o['label']) ?></td>
                            <td class="text-muted"><?= $h($o['desc']) ?></td>
                            <td class="text-center fw-bold" style="width: 90px"><?= (int) $o['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-muted small mb-4 d-print-none">
                Origens no GLPI: <?= $rawOrigins ? $h(implode(', ', $rawOrigins)) : 'nenhuma' ?>.
                O agrupamento é feito pelo nome (ver <code>ORIGIN_KEYWORDS</code>).
            </p>

            <h5 class="analytics-section">CHAMADOS POR TIPO</h5>
            <table class="table table-bordered mb-4 text-center">
                <thead><tr><th>Incidente</th><th>Requisição</th><th>Problema</th></tr></thead>
                <tbody>
                    <tr>
                        <td><?= (int) $data['types']['incident'] ?></td>
                        <td><?= (int) $data['types']['demand'] ?></td>
                        <td><?= (int) $data['types']['problem'] ?></td>
                    </tr>
                </tbody>
            </table>

            <h5 class="analytics-section">TEMPO DE SOLUÇÃO (DISTRIBUIÇÃO)</h5>
            <table class="table table-bordered mb-4">
                <tbody>
                    <?php foreach ($data['distribution'] as $d): ?>
                        <tr>
                            <td class="fw-bold" style="width: 34%"><?= $h($d['label']) ?></td>
                            <td class="text-muted"><?= $h($d['desc']) ?></td>
                            <td class="text-center fw-bold" style="width: 90px"><?= (int) $d['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h5 class="analytics-section">TOP TÉCNICOS</h5>
            <table class="table table-bordered mb-4">
                <thead><tr><th>Nome do técnico / Analista</th><th class="text-center" style="width: 90px">Quantidade</th></tr></thead>
                <tbody>
                    <?php foreach (PluginAnalyticsMonthlyReportData::paddedTechs($data) as $tech): ?>
                        <tr>
                            <td><?= $h($tech['name']) ?></td>
                            <td class="text-center fw-bold"><?= (int) $tech['total'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Indicadores complementares -->
            <h5 class="analytics-section">VOLUME E DISTRIBUIÇÃO DE CHAMADOS</h5>
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="analytics-chart-title">Por status</div>
                    <?php $bars($data['status_detail']); ?>
                </div>
                <div class="col-md-4">
                    <div class="analytics-chart-title">Por tipo</div>
                    <?php
                    $bars([
                        'Incidente'  => $data['types']['incident'],
                        'Requisição' => $data['types']['demand'],
                        'Problema'   => $data['types']['problem'],
                    ]);
                    ?>
                </div>
                <div class="col-md-4">
                    <div class="analytics-chart-title">Por origem</div>
                    <?php $bars($originBars); ?>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <h5 class="analytics-section">CATEGORIAS MAIS RECORRENTES (TOP 10)</h5>
                    <?php $bars(array_column($data['categories'], 'total', 'name')); ?>
                </div>
                <div class="col-md-6">
                    <h5 class="analytics-section">TOP 10 SOLICITANTES</h5>
                    <?php $bars(array_column($data['requesters'], 'total', 'name')); ?>
                </div>
            </div>

            <h5 class="analytics-section">PRAZOS E DESEMPENHO</h5>
            <table class="table table-bordered mb-4 text-center">
                <thead>
                    <tr>
                        <th>SLA solução (%)</th><th>SLA 1º atendimento (%)</th><th>TMA solução (h:mm)</th>
                        <th>TMA 1º atendimento (h:mm)</th><th>Tempo em Pendente (h:mm)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="fw-bold"><?= $h($dash($data['sla_percent'])) ?></td>
                        <td><?= $h($dash($data['sla_tto_percent'])) ?></td>
                        <td><?= $h($data['avg_formatted']) ?></td>
                        <td><?= $h($dash($data['avg_first_response'])) ?></td>
                        <td><?= $h($dash($data['avg_pending'])) ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="row">
                <div class="col-md-6">
                    <h5 class="analytics-section">PRODUTIVIDADE DA EQUIPE</h5>
                    <div class="analytics-chart-title">Chamados por grupo</div>
                    <?php $bars(array_column($data['groups'], 'total', 'name')); ?>
                    <div class="mt-3">
                        <span class="text-muted">Horas trabalhadas (tempo de ação, h:mm):</span>
                        <strong><?= $h($dash($data['work_hours'])) ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <h5 class="analytics-section">SATISFAÇÃO (CSAT)</h5>
                    <table class="table table-bordered text-center">
                        <thead><tr><th>Respondidas</th><th>Nota média (0–5)</th><th>% satisfeitos (4 ou 5)</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><?= $h($dash($data['csat']['answered'] ?? null)) ?></td>
                                <td><?= $h(isset($data['csat']['avg']) ? str_replace('.', ',', (string) $data['csat']['avg']) : '—') ?></td>
                                <td><?= $h($dash($data['csat']['satisfied_pct'] ?? null)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="text-muted small mt-3 mb-0">
                Blocos de volume, categorias, solicitantes e técnicos contam chamados abertos no mês. SLA, tempos
                e distribuição contam chamados solucionados no mês. "Atribuído" inclui Planejado; "Fechado" inclui
                Solucionado. Chamados na lixeira não são considerados.
            </p>
        </div>
    </div>
</div>

<style>
    .analytics-section {
        padding: .5rem .75rem;
        margin-bottom: .75rem;
        border-left: 4px solid var(--tblr-primary, #44546a);
        background: rgba(128, 128, 128, .08);
    }
    .analytics-chart-title {
        font-size: .8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--tblr-secondary, #6c757d);
        margin-bottom: .4rem;
    }
    .analytics-bar-row {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-size: .85rem;
        margin-bottom: .3rem;
    }
    .analytics-bar-label {
        flex: 0 0 38%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .analytics-bar-track {
        flex: 1 1 auto;
        height: 10px;
        border-radius: 5px;
        background: rgba(128, 128, 128, .15);
        overflow: hidden;
    }
    .analytics-bar-fill {
        display: block;
        height: 100%;
        border-radius: 5px;
        background: var(--tblr-primary, #44546a);
    }
    .analytics-bar-value {
        flex: 0 0 2.5rem;
        text-align: right;
        font-weight: 600;
    }
    @media print {
        .d-print-none { display: none !important; }
        #report-printable { border: none !important; box-shadow: none !important; }
    }
</style>

<?php Html::footer(); ?>
