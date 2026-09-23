<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class WorkPackageMonitoringView
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES);
    }

    public static function date(string $value): string
    {
        if (trim($value) === '') {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return self::escape($value);
        }
    }

    public static function renderStatusCards(array $rows): void
    {
        $totals = (new WorkPackageMonitoringService())->statusTotals($rows);
        echo "<div class='row g-3 mb-4'><div class='col-sm-6 col-lg-3'><div class='card card-body border-primary h-100'><div class='text-muted small'>Work Packages abertas</div><div class='fs-1 fw-bold text-primary'>" . count($rows) . '</div></div></div>';
        foreach ($totals as $status => $total) {
            echo "<div class='col-sm-6 col-lg-3'><div class='card card-body h-100'><div class='text-muted small text-truncate' title='" . self::escape($status) . "'>" . self::escape($status) . "</div><div class='fs-2 fw-bold'>" . (int) $total . '</div></div></div>';
        }
        echo '</div>';
    }

    public static function renderRows(array $rows, bool $showSource = false): void
    {
        if ($rows === []) {
            echo "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>Nenhuma Work Package aberta foi encontrada na última consulta.</div>";
            return;
        }
        echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>WP</th><th>Título</th><th>Status</th><th>Responsável</th><th>Cliente</th><th>Criada em</th><th>Atualizada em</th><th>Chamado vinculado</th>" . ($showSource ? '<th>Consulta por</th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $details = (array) ($row['details'] ?? []);
            $wpId = (int) ($row['openproject_work_package_id'] ?? $details['id'] ?? 0);
            $wpUrl = (string) ($details['openproject_url'] ?? '');
            $wpLink = $wpId > 0 && $wpUrl !== ''
                ? "<a href='" . self::escape($wpUrl) . "' target='_blank' rel='noopener'>#" . $wpId . " <i class='ti ti-external-link'></i></a>"
                : '#' . $wpId;
            $ticketLinks = [];
            foreach ((array) ($row['ticket_ids'] ?? $details['ticket_ids'] ?? []) as $ticketId) {
                $ticketId = (int) $ticketId;
                if ($ticketId > 0) {
                    $ticketLinks[] = "<a href='/front/ticket.form.php?id={$ticketId}' target='_blank' rel='noopener'>#{$ticketId} <i class='ti ti-external-link'></i></a>";
                }
            }
            echo '<tr><td>' . $wpLink . '</td><td><div class="text-wrap" style="min-width:220px">' . self::escape($details['subject'] ?? '') . '</div><small class="text-muted">' . self::escape($details['type'] ?? '') . '</small></td><td><span class="badge bg-azure-lt">' . self::escape($row['status_name'] ?? $details['status'] ?? '') . '</span></td><td>' . self::escape($row['responsible_name'] ?? $details['responsible'] ?? '') . '</td><td>' . self::escape($details['customer'] ?? '') . '</td><td>' . self::date((string) ($details['created_at'] ?? '')) . '</td><td>' . self::date((string) ($details['updated_at'] ?? '')) . '</td><td>' . ($ticketLinks === [] ? '<span class="text-muted">Não identificado</span>' : implode('<br>', $ticketLinks)) . '</td>';
            if ($showSource) {
                echo '<td>' . (int) ($row['users_id'] ?? 0) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
