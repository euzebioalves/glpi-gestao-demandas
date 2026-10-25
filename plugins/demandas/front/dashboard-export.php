<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\ManagementDashboardService;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\ManagementDashboard;
use GlpiPlugin\Demandas\DashboardView;
use GlpiPlugin\Demandas\DeliveryReview;
use GlpiPlugin\Demandas\ActionDashboard;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

DemandasProfile::checkRight(DemandasProfile::VIEW_DASHBOARD);
DemandasProfile::checkRight(DemandasProfile::EXPORT_DASHBOARD);

$format = strtolower((string) ($_GET['format'] ?? ''));
if (!in_array($format, ['pdf', 'xlsx'], true)) {
    throw new Glpi\Exception\Http\BadRequestHttpException('Formato de exportação inválido.');
}

$result = (new ManagementDashboardService())->result($_GET);
$rows = $result['rows'];
$summary = $result['summary'];
$bugsView = $result['filters']['view'] === 'bugs';
// A single export row per ticket in the focused view, preserving every WP.
if ($bugsView) {
    foreach ($rows as &$row) {
        foreach (['openproject_work_package_id', 'openproject_project_name', 'openproject_type_name', 'openproject_status', 'public_phase', 'last_synced_at'] as $field) {
            $row[$field] = implode(' | ', array_map(static fn($wp) => '#' . (int) $wp['openproject_work_package_id'] . ($field === 'openproject_work_package_id' ? '' : ': ' . (trim((string) ($wp[$field] ?? '')) ?: 'Não informado')), $row['work_packages']));
        }
    }
    unset($row);
}
$headers = ['Chamado', 'Título', 'Cliente', 'Classificação', 'Status GLPI', 'Idade (dias)', 'Work Package', 'Projeto', 'Tipo da WP', 'Status da WP', DemandasConfig::label('public_phase'), 'Abertura', 'Última sincronização'];
$summaryRows = [
    ['Visão', $bugsView ? 'Bugs e Melhorias' : 'Visão geral'],
    ['Filtros aplicados', json_encode($result['filters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ['Atualidade', 'Dados locais da última sincronização; não consultados em tempo real'],
    ['Escopo dos chamados', $result['filters']['ticket_scope'] === 'open' ? 'Abertos (exclui solucionados e fechados)' : 'Todos, inclusive solucionados e fechados'],
    ['Chamados no escopo', $summary['total']],
    ['Com Work Package', $summary['with_wp']],
    ['Sem Work Package', $summary['without_wp']],
    ['Idade média (dias)', $summary['average_age']],
    ['Acima de 30 dias', $summary['older_30']],
    ['WP sem sincronizar há 7 dias', $summary['stale']],
    ['Melhorias e bugs sem Work Package', $summary['undocumented_improvement_bug']],
    ['Bugs não solucionados', $summary['open_bug']],
    ['Melhorias não solucionadas', $summary['open_improvement']],
    ['Coletores não solucionados', $summary['open_collector']],
    ['Sugestões de melhoria', $summary['suggestions']],
    ['Chamados exportados após o drill-down', count($rows)],
];
if ($bugsView && DashboardView::canReview()) {
    $headers[] = 'Conferência de entregas';
    foreach (['any' => 'Chamados abertos com alguma entrega', 'all_delivered' => 'Todas as WPs em Entregue', 'partial' => 'Entrega parcial'] as $key => $label) $summaryRows[] = [$label, $result['delivery_counts'][$key]];
}
if ($bugsView) {
    array_push($headers, 'Responsáveis GLPI', 'Responsáveis OpenProject', 'Motivos de atenção', 'Permanência observada por WP');
    foreach ($rows as &$row) {
        $row['action_values'] = [implode(', ', $row['glpi_owners']), implode(', ', $row['op_owners']), implode(' | ', $row['attention']),
            implode(' | ', array_map(static fn($wp) => '#' . (int)$wp['openproject_work_package_id'] . ': ' . ($wp['stage_days'] === null ? 'Histórico insuficiente' : $wp['stage_days'] . ' dias observados de ' . $wp['observed_since'] . ' até ' . $wp['last_synced_at']), $row['work_packages']))];
    }
    unset($row);
    $summaryRows[] = ['Envelhecimento', 'Sequência local da mesma etapa até a última sincronização; não é a data exata da transição no OpenProject'];
    foreach (ActionDashboard::REASONS as $key => $label) {
        if (in_array($key, ActionDashboard::REVIEW_REASONS, true) && !DashboardView::canReview()) continue;
        $summaryRows[] = [$label, $result['actions']['counts'][$key]];
    }
}
$lastColumn = Coordinate::stringFromColumnIndex(count($headers));
foreach ($summary['top_clients'] as $position => $client) {
    $summaryRows[] = [($position + 1) . 'º cliente com mais melhorias ou bugs abertos — ' . $client['label'], $client['count']];
}

if ($format === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()->setCreator('GLPI — Gestão de Demandas')->setTitle(ManagementDashboard::getTypeName());
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Resumo');
    $sheet->setCellValue('A1', 'Indicador');
    $sheet->setCellValue('B1', 'Valor');
    foreach ($summaryRows as $rowIndex => $summaryRow) {
        $sheet->setCellValueExplicit([1, $rowIndex + 2], (string) $summaryRow[0], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([2, $rowIndex + 2], (string) $summaryRow[1], DataType::TYPE_STRING);
    }
    $sheet->getStyle('A1:B1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:B1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF206BC4');
    $sheet->getColumnDimension('A')->setAutoSize(true);
    $sheet->getColumnDimension('B')->setAutoSize(true);

    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Demandas');
    foreach ($headers as $index => $header) {
        $sheet->setCellValue([$index + 1, 1], $header);
    }
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF206BC4');
    foreach ($rows as $rowIndex => $row) {
        $values = [
            (int) $row['ticket_id'], (string) $row['ticket_name'], (string) ($row['entity_name'] ?: 'Entidade raiz'),
            (string) ($row['classification_label'] ?? 'Sem classificação'), (string) $row['glpi_status_name'],
            (int) $row['age_days'], $row['has_wp'] ? ($bugsView ? $row['openproject_work_package_id'] : (int) $row['openproject_work_package_id']) : '',
            (string) ($row['openproject_project_name'] ?? ''), (string) ($row['openproject_type_name'] ?? ''),
            (string) ($row['openproject_status'] ?? ''), (string) ($row['public_phase'] ?? ''),
            (string) $row['opened_at'], (string) ($row['last_synced_at'] ?? ''),
        ];
        if ($bugsView && DashboardView::canReview()) $values[] = DeliveryReview::LABELS[$row['delivery_state']];
        if ($bugsView) array_push($values, ...$row['action_values']);
        foreach ($values as $columnIndex => $value) {
            $cell = [$columnIndex + 1, $rowIndex + 2];
            if (is_int($value)) $sheet->setCellValue($cell, $value);
            else $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
    }
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:' . $lastColumn . max(1, count($rows) + 1));
    foreach (range('A', $lastColumn) as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="visao-gerencial-demandas.xlsx"');
    header('Cache-Control: no-store');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

$pdf = new GLPIPDF(['orientation' => 'L', 'font_size' => 7], count($rows), ManagementDashboard::getTypeName());
$html = '<p>Gerado em ' . htmlspecialchars(date('d/m/Y H:i:s')) . ' — ' . count($rows) . ' chamado(s)</p>';
$html .= '<table border="1" cellpadding="5" width="70%"><tr style="background-color:#206bc4;color:#ffffff;font-weight:bold"><th>Indicador</th><th>Valor</th></tr>';
foreach ($summaryRows as $summaryRow) {
    $html .= '<tr><td>' . htmlspecialchars((string) $summaryRow[0]) . '</td><td>' . htmlspecialchars((string) $summaryRow[1]) . '</td></tr>';
}
$html .= '</table><br>';
$html .= '<table border="1" cellpadding="4"><thead><tr style="background-color:#206bc4;color:#ffffff;font-weight:bold">';
foreach ($headers as $header) $html .= '<th>' . htmlspecialchars($header) . '</th>';
$html .= '</tr></thead><tbody>';
foreach ($rows as $row) {
    $values = [
        '#' . (int) $row['ticket_id'], $row['ticket_name'], $row['entity_name'] ?: 'Entidade raiz',
        $row['classification_label'] ?? 'Sem classificação', $row['glpi_status_name'], $row['age_days'],
        $row['has_wp'] ? ($bugsView ? $row['openproject_work_package_id'] : '#' . (int) $row['openproject_work_package_id']) : '—',
        $row['openproject_project_name'] ?: '—', $row['openproject_type_name'] ?: '—', $row['openproject_status'] ?: '—',
        $row['public_phase'] ?: '—', $row['opened_at'], $row['last_synced_at'] ?: '—',
    ];
    if ($bugsView && DashboardView::canReview()) $values[] = DeliveryReview::LABELS[$row['delivery_state']];
    if ($bugsView) array_push($values, ...$row['action_values']);
    $html .= '<tr>';
    foreach ($values as $value) $html .= '<td>' . htmlspecialchars((string) $value) . '</td>';
    $html .= '</tr>';
}
$html .= '</tbody></table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('visao-gerencial-demandas.pdf', 'D');
exit;
