<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use GLPIPDF;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Uses the PDF and spreadsheet libraries already distributed with GLPI. */
final class WorkPackageMonitoringExport
{
    public static function download(string $format, array $rows, array $filters, bool $consolidated): void
    {
        $title = $consolidated ? 'Consolidado de Work Packages' : 'Minhas Work Packages';
        $filename = $consolidated ? 'work-packages-consolidado' : 'minhas-work-packages';
        $headers = ['WP', 'Título', 'Tipo', 'Status', 'Responsável', 'Cliente', 'Criada em', 'Atualizada em', 'Chamados vinculados'];
        if ($consolidated) {
            $headers[] = 'Consulta por (ID GLPI)';
        }
        $summary = [['Listagem', $title], ['Gerado em', date('d/m/Y H:i:s')], ['Work Packages exportadas', count($rows)]];
        foreach (['status' => 'Status', 'ticket' => 'Chamado GLPI', 'customer' => 'Cliente', 'responsible' => 'Responsável'] as $key => $label) {
            $summary[] = [$label, $filters[$key] !== '' ? $filters[$key] : 'Todos'];
        }
        $summary[] = ['Origem', 'Últimas consultas armazenadas; todos os resultados dos filtros, sem limitar à página.'];

        if ($format === 'xlsx') {
            $book = new Spreadsheet();
            $book->getProperties()->setCreator('GLPI — Gestão de Demandas')->setTitle($title);
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Work Packages');
            foreach ($headers as $column => $header) {
                $sheet->setCellValue([$column + 1, 1], $header);
            }
            foreach ($rows as $index => $row) {
                foreach (self::values($row, $consolidated) as $column => $value) {
                    // API text is always a string, including leading '=' and '+'.
                    $sheet->setCellValueExplicit([$column + 1, $index + 2], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $lastColumn = $consolidated ? 'J' : 'I';
            $lastRow = max(1, count($rows) + 1);
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF206BC4');
            $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(true)->setVertical('top');
            foreach (range('A', $lastColumn) as $column) {
                $sheet->getColumnDimension($column)->setWidth($column === 'B' ? 55 : ($column === 'A' ? 12 : 25));
            }
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
            $sheet = $book->createSheet();
            $sheet->setTitle('Filtros');
            foreach ($summary as $index => $pair) {
                foreach ($pair as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 1], (string) $value, DataType::TYPE_STRING);
                }
            }
            $sheet->getColumnDimension('A')->setWidth(32);
            $sheet->getColumnDimension('B')->setWidth(95);
            $sheet->getStyle('A1:B' . count($summary))->getAlignment()->setWrapText(true);
            $book->setActiveSheetIndex(0);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
            return;
        }

        $pdf = new GLPIPDF(['orientation' => 'L', 'font_size' => 7], count($rows), $title);
        $html = '';
        foreach ($summary as [$label, $value]) {
            $html .= '<b>' . self::escape($label) . ':</b> ' . self::escape($value) . '<br>';
        }
        $html .= '<br><table border="1" cellpadding="4"><thead><tr style="background-color:#206bc4;color:#ffffff;font-weight:bold">';
        foreach ($headers as $header) {
            $html .= '<th>' . self::escape($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (self::values($row, $consolidated) as $value) {
                $html .= '<td>' . self::escape($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        if ($rows === []) {
            $html .= '<p>Nenhuma Work Package encontrada para os filtros selecionados.</p>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filename . '.pdf', 'D');
    }

    private static function values(array $row, bool $consolidated): array
    {
        $details = (array) ($row['details'] ?? []);
        $tickets = array_filter(array_map('intval', (array) ($row['ticket_ids'] ?? $details['ticket_ids'] ?? [])), static fn(int $id): bool => $id > 0);
        $values = [
            (int) ($row['openproject_work_package_id'] ?? $details['id'] ?? 0),
            (string) ($details['subject'] ?? ''), (string) ($details['type'] ?? ''),
            (string) ($row['status_name'] ?? $details['status'] ?? ''),
            (string) ($row['responsible_name'] ?? $details['responsible'] ?? ''),
            (string) ($details['customer'] ?? ''),
            self::date((string) ($details['created_at'] ?? '')),
            self::date((string) ($details['updated_at'] ?? '')),
            $tickets === [] ? 'Não identificado' : '#' . implode(', #', $tickets),
        ];
        if ($consolidated) {
            $values[] = (int) ($row['users_id'] ?? 0);
        }
        return $values;
    }

    private static function date(string $value): string
    {
        if (trim($value) === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
