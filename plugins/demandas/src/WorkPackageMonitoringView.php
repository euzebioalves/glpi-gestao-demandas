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

    public static function renderStatusCards(array $rows, array $filters = []): void
    {
        $totals = (new WorkPackageMonitoringService())->statusTotals($rows);
        echo "<div class='row g-3 mb-4'><div class='col-sm-6 col-lg-3'><a class='card card-body border-primary h-100 text-reset text-decoration-none' href='" . self::filterUrl($filters, null) . "'><div class='text-muted small'>Work Packages abertas</div><div class='fs-1 fw-bold text-primary'>" . count($rows) . '</div></a></div>';
        foreach ($totals as $status => $total) {
            $active = (string) ($filters['status'] ?? '') === $status ? ' border-primary shadow-sm' : '';
            echo "<div class='col-sm-6 col-lg-3'><a class='card card-body h-100 text-reset text-decoration-none" . $active . "' href='" . self::filterUrl($filters, $status) . "' aria-label='Filtrar por status " . self::escape($status) . "'><div class='text-muted small text-truncate' title='" . self::escape($status) . "'>" . self::escape($status) . "</div><div class='fs-2 fw-bold'>" . (int) $total . '</div></a></div>';
        }
        echo '</div>';
    }

    public static function renderFilters(array $options, array $filters): void
    {
        echo "<form class='card card-body mb-4' method='get'><div class='row align-items-end g-3'>";
        self::selectFilter('status', 'Status da WP', $options['status'] ?? [], (string) ($filters['status'] ?? ''), 'Todos os status');
        self::selectFilter('ticket', 'Chamado GLPI', $options['ticket'] ?? [], (string) ($filters['ticket'] ?? ''), 'Todos os chamados', '#');
        self::selectFilter('customer', 'Cliente (OpenProject)', $options['customer'] ?? [], (string) ($filters['customer'] ?? ''), 'Todos os clientes');
        self::selectFilter('responsible', 'Responsável da WP', $options['responsible'] ?? [], (string) ($filters['responsible'] ?? ''), 'Todos os responsáveis');
        echo "<div class='col-sm-6 col-lg-2 d-flex gap-2'><button class='btn btn-primary flex-fill'><i class='ti ti-filter me-1'></i>Filtrar</button><a class='btn btn-outline-secondary' href='?'><i class='ti ti-x'></i><span class='visually-hidden'>Limpar filtros</span></a></div></div></form>";
    }

    private static function selectFilter(string $name, string $label, array $values, string $selected, string $empty, string $prefix = ''): void
    {
        echo "<div class='col-sm-6 col-lg-2'><label class='form-label' for='demandas-filter-" . self::escape($name) . "'>" . self::escape($label) . "</label><select class='form-select' id='demandas-filter-" . self::escape($name) . "' name='" . self::escape($name) . "'><option value=''>" . self::escape($empty) . '</option>';
        foreach ($values as $value) {
            $value = (string) $value;
            echo "<option value='" . self::escape($value) . "'" . ($value === $selected ? ' selected' : '') . '>' . self::escape($prefix . $value) . '</option>';
        }
        echo '</select></div>';
    }

    private static function filterUrl(array $filters, ?string $status): string
    {
        $query = [];
        foreach (['ticket', 'customer', 'responsible'] as $name) {
            $value = trim((string) ($filters[$name] ?? ''));
            if ($value !== '') {
                $query[$name] = $value;
            }
        }
        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }
        return '?' . self::escape(http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public static function renderRows(array $rows, bool $showSource = false, bool $showAnalysis = false): void
    {
        if ($rows === []) {
            echo "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>Nenhuma Work Package aberta foi encontrada na última consulta.</div>";
            return;
        }
        echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>WP</th><th>Título</th><th>Status</th><th>Responsável</th><th>Cliente</th><th>Criada em</th><th>Atualizada em</th><th>Chamado vinculado</th>" . ($showSource ? '<th>Consulta por</th>' : '') . ($showAnalysis ? '<th>Ações</th>' : '') . '</tr></thead><tbody>';
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
            if ($showAnalysis) {
                echo '<td>' . ($ticketLinks === []
                    ? '<span class="text-muted small">Sem chamado</span>'
                    : "<a class='btn btn-sm btn-outline-primary' href='/plugins/demandas/front/work-package-analysis.php?wp={$wpId}'><i class='ti ti-sparkles me-1'></i>Preparar IA</a>") . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
