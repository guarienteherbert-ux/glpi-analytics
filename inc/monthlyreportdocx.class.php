<?php

/**
 * -------------------------------------------------------------------------
 * GLPI Service Desk Analytics - Exportação do Relatório Mensal em Word
 * -------------------------------------------------------------------------
 *
 * Gera um .docx no layout do modelo "Relatório Mensal de Service Desk"
 * (uma tabela única com cabeçalhos azul-ardósia, sub-cabeçalhos cinza e
 * valores em vermelho). Não depende de bibliotecas externas: monta o
 * WordprocessingML diretamente e empacota com ZipArchive (ext-zip, já exigida
 * pelo GLPI).
 *
 * @author    Herbert
 * @copyright 2026 Herbert
 * @license   GPL-2.0-or-later
 */

class PluginAnalyticsMonthlyReportDocx
{
    /** Partes de imagem acumuladas durante um build() (reiniciado a cada chamada). */
    private static array $media = [];

    private const LOGO_CM_H    = 1.6;
    private const TWIPS_PER_CM = 566.9291339;

    // Cores do modelo
    private const C_HEADER = '44546A';
    private const C_LABEL  = 'A5A5A5';
    private const C_SPACER = '595959';
    private const C_VALUE  = 'C00000';
    private const C_WHITE  = 'FFFFFF';

    // Grade de 60 colunas (divisível por 2, 3, 4, 5 e 6) para permitir tanto
    // as tabelas do modelo quanto barras de gráfico com resolução fina.
    private const COLS      = 60;
    private const COL_W     = 144;
    private const COL_LAST  = 149;   // 59 * 144 + 149 = 8645
    private const TABLE_W   = 8645;
    private const SPAN_DESC = 48;
    private const SPAN_QTY  = 12;

    // Gráficos de barras: rótulo | barra | valor
    private const BAR_LABEL = 24;
    private const BAR_AREA  = 28;
    private const BAR_VALUE = 8;
    private const C_BAR     = '44546A';

    /**
     * @param array<string, mixed> $data
     * @return string conteúdo binário do .docx
     */
    public static function build(
        array $data,
        string $clientName,
        string $monthName,
        ?string $companyLogoFile = null,
        ?string $clientLogoFile = null
    ): string {
        self::$media = [];

        $body = self::header($companyLogoFile, $clientLogoFile, $clientName, $monthName, (int) $data['period']['year']);
        $rows = '';

        // IDENTIFICAÇÃO
        $rows .= self::sectionHeader('IDENTIFICAÇÃO');
        $rows .= self::labelRow(['Cliente / Empresa', 'Mês de Referência', 'Ano'], 20);
        $rows .= self::valueRow([$clientName, $monthName, (string) $data['period']['year']], 20, 'left');
        $rows .= self::spacer();

        // STATUS
        $rows .= self::sectionHeader('STATUS DOS CHAMADOS (FIM DO MÊS)');
        $rows .= self::labelRow(['Novo', 'Atribuído', 'Pendente', 'Fechado', 'Total no mês'], 12);
        $rows .= self::valueRow([
            (string) $data['status']['novo'],
            (string) $data['status']['atribuido'],
            (string) $data['status']['pendente'],
            (string) $data['status']['fechado'],
            (string) $data['total'],
], 12);
        $rows .= self::spacer();

        // COMPARATIVO E SLA
        $rows .= self::sectionHeader('COMPARATIVO E SLA');
        $rows .= self::labelRow(['Total no mês anterior', '% dentro do prazo (SLA)', 'Tempo médio de solução'], 20);
        $rows .= self::valueRow([
            (string) $data['prev_total'],
            $data['sla_percent'] === null ? '—' : (string) $data['sla_percent'],
            (string) $data['avg_formatted'],
], 20);
        $rows .= self::spacer();

        // ORIGEM
        $rows .= self::sectionHeader('CHAMADOS POR ORIGEM');
        foreach ($data['origins'] as $o) {
            $rows .= self::labelQtyRow($o['label']);
            $rows .= self::descQtyRow($o['desc'], (string) $o['qty']);
        }
        $rows .= self::spacer();

        // TIPO
        $rows .= self::sectionHeader('CHAMADOS POR TIPO');
        $rows .= self::labelRow(['Incidente', 'Requisição', 'Problema'], 20);
        $rows .= self::valueRow([
            (string) $data['types']['incident'],
            (string) $data['types']['demand'],
            (string) $data['types']['problem'],
        ], 20);
        $rows .= self::spacer();

        // TEMPO DE SOLUÇÃO
        $rows .= self::sectionHeader('TEMPO DE SOLUÇÃO (DISTRIBUIÇÃO)');
        foreach ($data['distribution'] as $d) {
            $rows .= self::labelQtyRow($d['label']);
            $rows .= self::descQtyRow($d['desc'], (string) $d['qty']);
        }
        $rows .= self::spacer();

        // TOP TÉCNICOS
        $rows .= self::sectionHeader('TOP TÉCNICOS');
        foreach (PluginAnalyticsMonthlyReportData::paddedTechs($data) as $tech) {
            $rows .= self::labelQtyRow('Nome do técnico / Analista');
            $rows .= self::descQtyRow((string) $tech['name'], (string) $tech['total']);
        }
        $rows .= self::spacer(false);
        $tableA = self::table($rows);
        $rows   = '';

        // ---- Seções complementares (página 2) -------------------------
        $dash = static fn ($v): string => ($v === null || $v === '') ? '—' : (string) $v;

        // VOLUME E DISTRIBUIÇÃO
        $rows .= self::sectionHeader('VOLUME E DISTRIBUIÇÃO DE CHAMADOS');
        $rows .= self::chartTitle('Por status');
        $rows .= self::barRows($data['status_detail']);
        $rows .= self::chartTitle('Por tipo');
        $rows .= self::barRows([
            'Incidente'  => $data['types']['incident'],
            'Requisição' => $data['types']['demand'],
            'Problema'   => $data['types']['problem'],
        ]);
        $originBars = [];
        foreach ($data['origins'] as $o) {
            $originBars[$o['label']] = $o['qty'];
        }
        $rows .= self::chartTitle('Por origem');
        $rows .= self::barRows($originBars);
        $rows .= self::spacer();

        // CATEGORIAS
        $rows .= self::sectionHeader('CATEGORIAS MAIS RECORRENTES (TOP 10)');
        $rows .= self::barRows(array_column($data['categories'], 'total', 'name'));
        $rows .= self::spacer();

        // SOLICITANTES
        $rows .= self::sectionHeader('TOP 10 SOLICITANTES');
        $rows .= self::barRows(array_column($data['requesters'], 'total', 'name'));
        $rows .= self::spacer();

        // PRAZOS E DESEMPENHO
        $rows .= self::sectionHeader('PRAZOS E DESEMPENHO');
        $rows .= self::labelRow([
            'SLA solução (%)', 'SLA 1º atend. (%)', 'TMA solução', 'TMA 1º atend.', 'Tempo Pendente',
        ], 12, 18);
        $rows .= self::valueRow([
            $dash($data['sla_percent']),
            $dash($data['sla_tto_percent']),
            (string) $data['avg_formatted'],
            $dash($data['avg_first_response']),
            $dash($data['avg_pending']),
        ], 12);
        $rows .= self::spacer();

        // PRODUTIVIDADE
        $rows .= self::sectionHeader('PRODUTIVIDADE DA EQUIPE');
        if (!empty($data['groups'])) {
            $rows .= self::chartTitle('Chamados por grupo');
            $rows .= self::barRows(array_column($data['groups'], 'total', 'name'));
        }
        $rows .= self::labelRow(['Horas trabalhadas (tempo de ação, h:mm)'], 60);
        $rows .= self::valueRow([$dash($data['work_hours'])], 60);
        $rows .= self::spacer();

        // CSAT
        $csat = $data['csat'] ?? null;
        $rows .= self::sectionHeader('SATISFAÇÃO (CSAT)');
        $rows .= self::labelRow(['Pesquisas respondidas', 'Nota média (0–5)', '% satisfeitos (nota 4 ou 5)'], 20);
        $rows .= self::valueRow([
            $dash($csat['answered'] ?? null),
            $dash($csat['avg'] ?? null) === '—' ? '—' : str_replace('.', ',', (string) $csat['avg']),
            $dash($csat['satisfied_pct'] ?? null),
        ], 20);
        $rows .= self::spacer(false);

        $tableB = self::table($rows);

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body . $tableA . self::title('Indicadores Complementares') . $tableB
            . '<w:p/>'
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1701" w:bottom="1417" w:left="1701" w:header="0" w:footer="0" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';

        return self::zip($document);
    }

    // ------------------------------------------------------------------
    // Estrutura de linhas
    // ------------------------------------------------------------------

    private static function table(string $rows): string
    {
        return '<w:tbl><w:tblPr>'
            . '<w:tblW w:w="' . self::TABLE_W . '" w:type="dxa"/>'
            . '<w:tblInd w:w="38" w:type="dxa"/>'
            . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
            . '</w:tblPr><w:tblGrid>' . self::grid() . '</w:tblGrid>'
            . $rows . '</w:tbl>';
    }

    private static function sectionHeader(string $text): string
    {
        return self::row([
            self::cell($text, 0, self::COLS, [
                'fill' => self::C_HEADER, 'color' => self::C_WHITE, 'bold' => true, 'size' => 24,
            ]),
        ]);
    }

    /** @param list<string> $labels */
    private static function labelRow(array $labels, int $span, int $size = 20): string
    {
        $cells = [];
        foreach ($labels as $i => $label) {
            $cells[] = self::cell($label, $i * $span, $span, [
                'fill' => self::C_LABEL, 'color' => self::C_WHITE, 'bold' => true, 'size' => $size,
            ]);
        }

        return self::row($cells);
    }

    /** @param list<string> $values */
    private static function valueRow(array $values, int $span, string $align = 'center'): string
    {
        $cells = [];
        foreach ($values as $i => $value) {
            $cells[] = self::cell($value, $i * $span, $span, [
                'color' => self::C_VALUE, 'bold' => true, 'size' => 20, 'align' => $align, 'border' => true,
            ]);
        }

        return self::row($cells);
    }

    private static function labelQtyRow(string $label): string
    {
        return self::row([
            self::cell($label, 0, self::SPAN_DESC, [
                'fill' => self::C_LABEL, 'color' => self::C_WHITE, 'bold' => true, 'size' => 20,
            ]),
            self::cell('Quantidade', self::SPAN_DESC, self::SPAN_QTY, [
                'fill' => self::C_LABEL, 'color' => self::C_WHITE, 'bold' => true, 'size' => 20, 'align' => 'right',
            ]),
        ]);
    }

    private static function descQtyRow(string $desc, string $qty): string
    {
        return self::row([
            self::cell($desc, 0, self::SPAN_DESC, ['size' => 18, 'border' => true]),
            self::cell($qty, self::SPAN_DESC, self::SPAN_QTY, [
                'color' => self::C_VALUE, 'bold' => true, 'size' => 20, 'align' => 'center', 'border' => true,
            ]),
        ]);
    }

    /** Faixa cinza-escura fina + respiro branco entre as seções. */
    private static function spacer(bool $gap = true): string
    {
        $bar = self::row([
            self::cell('', 0, self::COLS, ['fill' => self::C_SPACER, 'size' => 6]),
        ], 100);

        if (!$gap) {
            return $bar;
        }

        return $bar . self::row([self::cell('', 0, self::COLS, ['size' => 6])], 100);
    }

    /** @param list<string> $cells */
    private static function row(array $cells, ?int $exactHeight = null): string
    {
        $trPr = '<w:trPr><w:cantSplit/>'
            . ($exactHeight !== null ? '<w:trHeight w:val="' . $exactHeight . '" w:hRule="exact"/>' : '')
            . '</w:trPr>';

        return '<w:tr>' . $trPr . implode('', $cells) . '</w:tr>';
    }

    // ------------------------------------------------------------------
    // Mini gráficos de barras (feitos com células sombreadas da tabela)
    // ------------------------------------------------------------------

    private static function chartTitle(string $title): string
    {
        return self::row([
            self::cell($title, 0, self::COLS, [
                'fill' => self::C_LABEL, 'color' => self::C_WHITE, 'bold' => true, 'size' => 20,
            ]),
        ]);
    }

    /**
     * Uma linha por item: rótulo | barra proporcional | valor.
     * A maior barra ocupa toda a área; valores > 0 têm ao menos 1 coluna.
     *
     * @param array<string, int|float> $items rótulo => quantidade
     */
    private static function barRows(array $items): string
    {
        if (empty($items)) {
            return self::row([
                self::cell('Sem dados no período', 0, self::COLS, ['size' => 18, 'border' => true]),
            ]);
        }

        $max  = max(1, (int) max($items));
        $xml  = '';
        $gap  = '<w:tcBorders><w:top w:val="single" w:sz="24" w:space="0" w:color="FFFFFF"/>'
              . '<w:left w:val="nil"/>'
              . '<w:bottom w:val="single" w:sz="24" w:space="0" w:color="FFFFFF"/>'
              . '<w:right w:val="nil"/></w:tcBorders>';

        foreach ($items as $label => $qty) {
            $qty   = (int) $qty;
            $steps = $qty > 0 ? max(1, (int) round($qty / $max * self::BAR_AREA)) : 0;
            $rest  = self::BAR_AREA - $steps;

            $cells   = [];
            $cells[] = self::cell(mb_strimwidth((string) $label, 0, 42, '…'), 0, self::BAR_LABEL, ['size' => 18]);

            $col = self::BAR_LABEL;
            if ($steps > 0) {
                $cells[] = self::cell('', $col, $steps, ['fill' => self::C_BAR, 'size' => 12, 'borders_xml' => $gap]);
                $col += $steps;
            }
            if ($rest > 0) {
                $cells[] = self::cell('', $col, $rest, ['size' => 12]);
                $col += $rest;
            }
            $cells[] = self::cell((string) $qty, $col, self::BAR_VALUE, [
                'color' => self::C_VALUE, 'bold' => true, 'size' => 18, 'align' => 'right',
            ]);

            $xml .= self::row($cells);
        }

        return $xml;
    }

    // ------------------------------------------------------------------
    // Células
    // ------------------------------------------------------------------

    /**
     * @param array{fill?: string, color?: string, bold?: bool, size?: int, align?: string, border?: bool, borders_xml?: string} $o
     */
    private static function cell(string $text, int $startCol, int $span, array $o = []): string
    {
        $width = self::spanWidth($startCol, $span);
        $size  = $o['size'] ?? 20;

        $borders = isset($o['borders_xml'])
            ? $o['borders_xml']
            : (empty($o['border'])
            ? '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
            : '<w:tcBorders>'
                . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
                . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
                . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
                . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
                . '</w:tcBorders>');

        $shd = isset($o['fill'])
            ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>'
            : '';

        $rPr = '<w:rPr>'
            . (!empty($o['bold']) ? '<w:b/><w:bCs/>' : '')
            . (isset($o['color']) ? '<w:color w:val="' . $o['color'] . '"/>' : '')
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>'
            . '<w:lang w:val="pt-BR"/></w:rPr>';

        $jc = isset($o['align']) && $o['align'] !== 'left'
            ? '<w:jc w:val="' . $o['align'] . '"/>'
            : '';

        $run = $text === ''
            ? ''
            : '<w:r>' . $rPr . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r>';

        return '<w:tc><w:tcPr>'
            . '<w:tcW w:w="' . $width . '" w:type="dxa"/>'
            . '<w:gridSpan w:val="' . $span . '"/>'
            . $borders . $shd
            . '<w:vAlign w:val="center"/>'
            . '</w:tcPr>'
            . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>' . $jc
            . $rPr . '</w:pPr>' . $run . '</w:p>'
            . '</w:tc>';
    }

    /**
     * Cabeçalho do relatório: logo da empresa à esquerda, título ao centro,
     * logo do cliente à direita (mesma disposição do PDF de impressão).
     */
    private static function header(
        ?string $companyLogoFile,
        ?string $clientLogoFile,
        string $clientName,
        string $monthName,
        int $year
    ): string {
        $left  = $companyLogoFile !== null ? self::imageCell(@file_get_contents($companyLogoFile) ?: '', 0, 12, self::LOGO_CM_W(), self::LOGO_CM_H, true) : self::cell('', 0, 12);
        $right = $clientLogoFile !== null ? self::imageCell(@file_get_contents($clientLogoFile) ?: '', 48, 12, self::LOGO_CM_W(), self::LOGO_CM_H, true) : self::cell('', 48, 12);

        $titleRPr = '<w:rPr><w:b/><w:bCs/><w:sz w:val="28"/><w:szCs w:val="28"/><w:lang w:val="pt-BR"/></w:rPr>';
        $subRPr   = '<w:rPr><w:color w:val="595959"/><w:sz w:val="18"/><w:szCs w:val="18"/><w:lang w:val="pt-BR"/></w:rPr>';
        $titlePar = '<w:p><w:pPr><w:spacing w:after="40" w:line="240" w:lineRule="auto"/><w:jc w:val="center"/>' . $titleRPr . '</w:pPr>'
            . '<w:r>' . $titleRPr . '<w:t>Relatório Mensal de Service Desk</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="center"/>' . $subRPr . '</w:pPr>'
            . '<w:r>' . $subRPr . '<w:t xml:space="preserve">' . self::esc($clientName . ' — ' . $monthName . ' de ' . $year) . '</w:t></w:r></w:p>';

        $center = '<w:tc><w:tcPr><w:tcW w:w="' . self::spanWidth(12, 36) . '" w:type="dxa"/>'
            . '<w:gridSpan w:val="36"/><w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
            . '<w:vAlign w:val="center"/></w:tcPr>' . $titlePar . '</w:tc>';

        return self::table(self::row([$left, $center, $right])) . '<w:p/>';
    }

    private static function LOGO_CM_W(): float
    {
        return 5.0;
    }

    /**
     * Registra um PNG como parte de mídia do .docx e devolve a célula que o
     * exibe, centralizado, no tamanho físico dado (cm). $center desenha sem
     * bordas (uso em cabeçalhos); caso contrário usa borda fina (gráficos).
     */
    private static function imageCell(
        string $pngBytes,
        int $startCol,
        int $span,
        float $cmW,
        float $cmH,
        bool $borderless = false
    ): string {
        $width = self::spanWidth($startCol, $span);

        if ($pngBytes === '') {
            return self::cell('', $startCol, $span);
        }

        $idx  = count(self::$media) + 1;
        $rId  = 'rIdImg' . $idx;
        self::$media[] = ['rId' => $rId, 'file' => 'image' . $idx . '.png', 'bytes' => $pngBytes];

        $emuW = (int) round($cmW * 360000);
        $emuH = (int) round($cmH * 360000);
        $docPr = 100 + $idx;

        $drawing = '<w:r><w:drawing>'
            . '<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $emuW . '" cy="' . $emuH . '"/>'
            . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="' . $docPr . '" name="Imagem' . $idx . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $docPr . '" name="Imagem' . $idx . '.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rId . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $emuW . '" cy="' . $emuH . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';

        $borders = $borderless
            ? '<w:tcBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/></w:tcBorders>'
            : '<w:tcBorders>'
                . '<w:top w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
                . '<w:left w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
                . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
                . '<w:right w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'
                . '</w:tcBorders>';

        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>'
            . '<w:gridSpan w:val="' . $span . '"/>' . $borders . '<w:vAlign w:val="center"/></w:tcPr>'
            . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/><w:jc w:val="center"/></w:pPr>' . $drawing . '</w:p>'
            . '</w:tc>';
    }


    private static function title(string $text): string
    {
        $rPr = '<w:rPr><w:b/><w:bCs/><w:sz w:val="28"/><w:szCs w:val="28"/><w:lang w:val="pt-BR"/></w:rPr>';

        return '<w:p><w:pPr>'
            . '<w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="CCCCCC"/></w:pBdr>'
            . '<w:spacing w:before="200" w:after="120" w:line="240" w:lineRule="auto"/>' . $rPr . '</w:pPr>'
            . '<w:r>' . $rPr . '<w:t>' . self::esc($text) . '</w:t></w:r></w:p>';
    }

    // ------------------------------------------------------------------
    // Grade, escape e empacotamento
    // ------------------------------------------------------------------

    /** @return list<int> */
    private static function colWidths(): array
    {
        return array_merge(array_fill(0, self::COLS - 1, self::COL_W), [self::COL_LAST]);
    }

    private static function grid(): string
    {
        $xml = '';
        foreach (self::colWidths() as $w) {
            $xml .= '<w:gridCol w:w="' . $w . '"/>';
        }

        return $xml;
    }

    private static function spanWidth(int $start, int $span): int
    {
        return array_sum(array_slice(self::colWidths(), $start, $span));
    }

    private static function esc(string $text): string
    {
        // Remove caracteres de controle inválidos em XML 1.0
        $text = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function zip(string $documentXml): string
    {
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $imageRels = '';
        foreach (self::$media as $m) {
            $imageRels .= '<Relationship Id="' . $m['rId'] . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                . 'Target="media/' . $m['file'] . '"/>';
        }

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . $imageRels
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="' . $ns . '">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="pt-BR" w:eastAsia="en-US" w:bidi="ar-SA"/>'
            . '</w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/>'
            . '<w:uiPriority w:val="99"/><w:semiHidden/><w:unhideWhenUsed/>'
            . '<w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar>'
            . '<w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/>'
            . '<w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/>'
            . '</w:tblCellMar></w:tblPr></w:style>'
            . '</w:styles>';

        $tmpDir = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $path   = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . uniqid('analytics_', true) . '.docx';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo .docx temporário.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);
        $zip->addFromString('word/styles.xml', $styles);
        foreach (self::$media as $m) {
            $zip->addFromString('word/media/' . $m['file'], $m['bytes']);
        }
        $zip->close();

        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }
}
