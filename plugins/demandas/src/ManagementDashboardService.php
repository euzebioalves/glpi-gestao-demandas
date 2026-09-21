<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use Session;
use Ticket;

final class ManagementDashboardService
{
    public const AGE_BUCKETS = [
        '0-7' => '0 a 7 dias',
        '8-30' => '8 a 30 dias',
        '31-60' => '31 a 60 dias',
        '61-90' => '61 a 90 dias',
        '91+' => 'Mais de 90 dias',
    ];

    public function result(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $allRows = $this->loadRows();
        $scopeRows = array_values(array_filter($allRows, fn(array $row): bool => $this->matchesBaseFilters($row, $filters)));
        $rows = array_values(array_filter($scopeRows, fn(array $row): bool => $this->matchesDrillDown($row, $filters)));

        return [
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $this->summary($scopeRows),
            'breakdowns' => [
                'age_bucket' => $this->ageBreakdown($scopeRows),
                'glpi_status' => $this->breakdown($scopeRows, 'glpi_status_name', 'glpi_status'),
                'op_status' => $this->breakdown($scopeRows, 'openproject_status'),
                'public_phase' => $this->breakdown($scopeRows, 'public_phase'),
                'project' => $this->breakdown($scopeRows, 'openproject_project_name'),
                'type' => $this->breakdown($scopeRows, 'openproject_type_name'),
                'classification' => $this->breakdown($scopeRows, 'classification_label', 'classification_kind'),
            ],
            'options' => [
                'op_status' => $this->options($allRows, 'openproject_status'),
                'public_phase' => $this->options($allRows, 'public_phase'),
                'project' => $this->options($allRows, 'openproject_project_name'),
                'type' => $this->options($allRows, 'openproject_type_name'),
                'glpi_status' => $this->options($allRows, 'glpi_status_name', 'glpi_status'),
                'client' => $this->options($allRows, 'entity_name'),
            ],
        ];
    }

    private function loadRows(): array
    {
        $entities = array_values(array_filter(array_map('intval', Session::getActiveEntities()), static fn(int $id): bool => $id >= 0));
        if ($entities === []) {
            return [];
        }

        $entitySql = implode(',', $entities);
        $db = \DBConnection::getReadConnection();
        $sql = "SELECT
                    t.id AS ticket_id,
                    t.name AS ticket_name,
                    t.status AS glpi_status,
                    t.date AS opened_at,
                    t.date_mod AS ticket_updated_at,
                    t.entities_id,
                    e.completename AS entity_name,
                    l.openproject_work_package_id,
                    l.openproject_project_name,
                    l.openproject_type_name,
                    l.openproject_status,
                    l.public_phase,
                    l.last_synced_at
                FROM glpi_tickets t
                LEFT JOIN glpi_entities e ON e.id = t.entities_id
                LEFT JOIN glpi_plugin_demandas_links l ON l.tickets_id = t.id
                WHERE t.is_deleted = 0 AND t.entities_id IN ({$entitySql})
                ORDER BY t.date DESC, t.id DESC";

        $rows = [];
        $classifications = [];
        foreach ($db->doQuery($sql) as $row) {
            $opened = strtotime((string) ($row['opened_at'] ?? '')) ?: time();
            $age = max(0, (int) floor((time() - $opened) / 86400));
            $wpId = (int) ($row['openproject_work_package_id'] ?? 0);
            $lastSync = !empty($row['last_synced_at']) ? strtotime((string) $row['last_synced_at']) : false;
            $row['age_days'] = $age;
            $row['age_bucket'] = $this->ageBucket($age);
            $row['has_wp'] = $wpId > 0;
            $row['sync_stale'] = $wpId > 0 && ($lastSync === false || $lastSync < strtotime('-7 days'));
            $row['glpi_status_name'] = Ticket::getStatus((int) $row['glpi_status']);
            $ticketId = (int) $row['ticket_id'];
            if (!isset($classifications[$ticketId])) {
                $classification = ['value' => '', 'label' => 'Sem classificação'];
                $ticket = new Ticket();
                if ($ticket->getFromDB($ticketId)) {
                    try {
                        $classification = ClassificationPolicy::resolve($ticket);
                    } catch (\Throwable) {
                        // O painel permanece disponível mesmo que a origem da
                        // classificação esteja temporariamente inconsistente.
                    }
                }
                $label = trim((string) ($classification['label'] ?? '')) ?: 'Sem classificação';
                $classifications[$ticketId] = [
                    'value' => (string) ($classification['value'] ?? ''),
                    'label' => $label,
                    'kind' => $this->classificationKind($label),
                ];
            }
            $row['classification_value'] = $classifications[$ticketId]['value'];
            $row['classification_label'] = $classifications[$ticketId]['label'];
            $row['classification_kind'] = $classifications[$ticketId]['kind'];
            $row['is_open'] = !in_array((int) $row['glpi_status'], [5, 6], true);
            $rows[] = $row;
        }
        return $rows;
    }

    private function normalizeFilters(array $input): array
    {
        $hasWp = (string) ($input['has_wp'] ?? '');
        return [
            'date_from' => $this->date((string) ($input['date_from'] ?? '')),
            'date_to' => $this->date((string) ($input['date_to'] ?? '')),
            'has_wp' => in_array($hasWp, ['yes', 'no'], true) ? $hasWp : '',
            'age_bucket' => array_key_exists((string) ($input['age_bucket'] ?? ''), self::AGE_BUCKETS) ? (string) $input['age_bucket'] : '',
            'op_status' => trim((string) ($input['op_status'] ?? '')),
            'public_phase' => trim((string) ($input['public_phase'] ?? '')),
            'project' => trim((string) ($input['project'] ?? '')),
            'type' => trim((string) ($input['type'] ?? '')),
            'glpi_status' => ctype_digit((string) ($input['glpi_status'] ?? '')) ? (string) $input['glpi_status'] : '',
            'client' => trim((string) ($input['client'] ?? '')),
            'classification' => in_array((string) ($input['classification'] ?? ''), [
                'bug', 'improvement', 'suggestion', 'collector', 'other',
            ], true) ? (string) $input['classification'] : '',
            'q' => trim((string) ($input['q'] ?? '')),
            'metric' => in_array((string) ($input['metric'] ?? ''), [
                'all', 'with_wp', 'without_wp', 'older_30', 'stale',
                'undocumented_improvement_bug', 'open_bug', 'open_improvement',
                'open_collector', 'suggestions', 'open_improvement_bug',
            ], true) ? (string) $input['metric'] : '',
        ];
    }

    private function matchesBaseFilters(array $row, array $filters): bool
    {
        $opened = substr((string) $row['opened_at'], 0, 10);
        if ($filters['date_from'] !== '' && $opened < $filters['date_from']) return false;
        if ($filters['date_to'] !== '' && $opened > $filters['date_to']) return false;
        if ($filters['has_wp'] === 'yes' && !$row['has_wp']) return false;
        if ($filters['has_wp'] === 'no' && $row['has_wp']) return false;
        foreach (['age_bucket', 'op_status', 'public_phase', 'project', 'type'] as $field) {
            $rowValue = (string) ($row[$field === 'project' ? 'openproject_project_name' : ($field === 'type' ? 'openproject_type_name' : $field)] ?? '');
            if ($filters[$field] !== '' && !$this->valueMatches($rowValue, $filters[$field])) return false;
        }
        if ($filters['glpi_status'] !== '' && (string) $row['glpi_status'] !== $filters['glpi_status']) return false;
        if ($filters['client'] !== '' && !$this->valueMatches((string) ($row['entity_name'] ?? ''), $filters['client'])) return false;
        if ($filters['classification'] !== '' && (string) $row['classification_kind'] !== $filters['classification']) return false;
        if ($filters['q'] !== '') {
            $haystack = mb_strtolower((string) $row['ticket_id'] . ' ' . (string) $row['ticket_name']);
            if (!str_contains($haystack, mb_strtolower($filters['q']))) return false;
        }
        return true;
    }

    private function matchesDrillDown(array $row, array $filters): bool
    {
        return match ($filters['metric']) {
            'with_wp' => $row['has_wp'],
            'without_wp' => !$row['has_wp'],
            'older_30' => $row['age_days'] > 30,
            'stale' => $row['sync_stale'],
            'undocumented_improvement_bug' => !$row['has_wp'] && in_array($row['classification_kind'], ['improvement', 'bug'], true),
            'open_bug' => $row['is_open'] && $row['classification_kind'] === 'bug',
            'open_improvement' => $row['is_open'] && $row['classification_kind'] === 'improvement',
            'open_collector' => $row['is_open'] && $row['classification_kind'] === 'collector',
            'suggestions' => $row['classification_kind'] === 'suggestion',
            'open_improvement_bug' => $row['is_open'] && in_array($row['classification_kind'], ['improvement', 'bug'], true),
            default => true,
        };
    }

    private function summary(array $rows): array
    {
        $tickets = [];
        foreach ($rows as $row) {
            $ticketId = (int) $row['ticket_id'];
            if (!isset($tickets[$ticketId])) {
                $tickets[$ticketId] = $row;
            } else {
                $tickets[$ticketId]['has_wp'] = $tickets[$ticketId]['has_wp'] || $row['has_wp'];
                $tickets[$ticketId]['sync_stale'] = $tickets[$ticketId]['sync_stale'] || $row['sync_stale'];
            }
        }
        $ticketRows = array_values($tickets);
        $total = count($ticketRows);
        $withWp = count(array_filter($ticketRows, static fn(array $row): bool => $row['has_wp']));
        $ageTotal = array_sum(array_column($ticketRows, 'age_days'));
        $undocumented = count(array_filter($ticketRows, static fn(array $row): bool =>
            !$row['has_wp'] && in_array($row['classification_kind'], ['improvement', 'bug'], true)
        ));
        return [
            'total' => $total,
            'with_wp' => $withWp,
            'without_wp' => $total - $withWp,
            'average_age' => $total > 0 ? round($ageTotal / $total, 1) : 0,
            'older_30' => count(array_filter($ticketRows, static fn(array $row): bool => $row['age_days'] > 30)),
            'stale' => count(array_filter($ticketRows, static fn(array $row): bool => $row['sync_stale'])),
            'undocumented_improvement_bug' => $undocumented,
            'open_bug' => count(array_filter($ticketRows, static fn(array $row): bool => $row['is_open'] && $row['classification_kind'] === 'bug')),
            'open_improvement' => count(array_filter($ticketRows, static fn(array $row): bool => $row['is_open'] && $row['classification_kind'] === 'improvement')),
            'open_collector' => count(array_filter($ticketRows, static fn(array $row): bool => $row['is_open'] && $row['classification_kind'] === 'collector')),
            'suggestions' => count(array_filter($ticketRows, static fn(array $row): bool => $row['classification_kind'] === 'suggestion')),
            'top_clients' => $this->topClients($ticketRows),
        ];
    }

    private function topClients(array $ticketRows): array
    {
        $clients = [];
        foreach ($ticketRows as $row) {
            if (!$row['is_open'] || !in_array($row['classification_kind'], ['improvement', 'bug'], true)) {
                continue;
            }
            $name = trim((string) ($row['entity_name'] ?? '')) ?: 'Entidade raiz';
            $clients[$name] = ($clients[$name] ?? 0) + 1;
        }
        arsort($clients, SORT_NUMERIC);
        $result = [];
        foreach (array_slice($clients, 0, 5, true) as $name => $count) {
            $result[] = ['label' => $name, 'value' => $name === 'Entidade raiz' ? '__empty__' : $name, 'count' => $count];
        }
        return $result;
    }

    private function classificationKind(string $label): string
    {
        $normalized = mb_strtolower(trim($label));
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        if (str_contains($normalized, 'sugestao') && str_contains($normalized, 'melhoria')) return 'suggestion';
        if (str_contains($normalized, 'bug') || (str_contains($normalized, 'erro') && str_contains($normalized, 'tecnic'))) return 'bug';
        if (str_contains($normalized, 'coletor')) return 'collector';
        if (str_contains($normalized, 'melhoria')) return 'improvement';
        return 'other';
    }

    private function breakdown(array $rows, string $labelField, ?string $valueField = null): array
    {
        $values = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$labelField] ?? '')) ?: 'Não informado';
            $value = (string) ($row[$valueField ?? $labelField] ?? '');
            if ($value === '') $value = '__empty__';
            $key = $value . "\0" . $label;
            $values[$key] ??= ['label' => $label, 'value' => $value, 'tickets' => []];
            $values[$key]['tickets'][(int) $row['ticket_id']] = true;
        }
        $values = array_map(static function (array $item): array {
            $item['count'] = count($item['tickets']);
            unset($item['tickets']);
            return $item;
        }, array_values($values));
        usort($values, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcasecmp($a['label'], $b['label']));
        return $values;
    }

    private function options(array $rows, string $labelField, ?string $valueField = null): array
    {
        $options = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row[$labelField] ?? ''));
            $value = (string) ($row[$valueField ?? $labelField] ?? '');
            if ($label !== '' && $value !== '') $options[$value] = $label;
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    private function ageBreakdown(array $rows): array
    {
        $counts = array_fill_keys(array_keys(self::AGE_BUCKETS), 0);
        $seen = [];
        foreach ($rows as $row) {
            $bucket = (string) ($row['age_bucket'] ?? '');
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            if (array_key_exists($bucket, $counts) && !isset($seen[$ticketId])) {
                $counts[$bucket]++;
                $seen[$ticketId] = true;
            }
        }

        $values = [];
        foreach (self::AGE_BUCKETS as $value => $label) {
            $values[] = ['label' => $label, 'value' => $value, 'count' => $counts[$value]];
        }
        return $values;
    }

    private function ageBucket(int $age): string
    {
        return $age <= 7 ? '0-7' : ($age <= 30 ? '8-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : '91+')));
    }

    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private function valueMatches(string $rowValue, string $filterValue): bool
    {
        return $filterValue === '__empty__' ? $rowValue === '' : $rowValue === $filterValue;
    }
}
