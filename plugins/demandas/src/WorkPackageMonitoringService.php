<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use RuntimeException;
use Ticket;

/** Stores the result of personal OpenProject queries and the related alerts. */
final class WorkPackageMonitoringService
{
    private const MONITORS = 'glpi_plugin_demandas_work_package_monitors';
    private const NOTIFICATIONS = 'glpi_plugin_demandas_work_package_notifications';

    private object $db;

    public function __construct()
    {
        $this->db = DBConnection::getReadConnection();
    }

    /**
     * Refreshes only the signed-in user's scope. A failed API request never
     * changes a prior snapshot, so a transient OpenProject outage cannot make
     * the consolidated view appear empty.
     */
    public function refreshForUser(int $userId): int
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuário inválido para consultar Work Packages.');
        }
        if (!Config::hasPersonalToken($userId)) {
            throw new RuntimeException('Configure o seu token pessoal do OpenProject em Minhas configurações antes de consultar as Work Packages.');
        }

        $client = OpenProjectClient::forUser($userId);
        $workPackages = $client->getOpenDemandWorkPackagesForCurrentUser();
        $workPackageIds = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), $workPackages)));
        $ticketMap = $this->ticketMap($workPackageIds);
        $now = date('Y-m-d H:i:s');

        // Only after the complete paged query succeeded can absent WPs be
        // marked closed/out of scope in this user's snapshot.
        $this->db->update(self::MONITORS, ['is_open' => 0, 'date_mod' => $now], ['users_id' => $userId, 'is_open' => 1]);

        foreach ($workPackages as $workPackage) {
            $details = $client->summarizeWorkPackage($workPackage);
            $workPackageId = (int) ($details['id'] ?? 0);
            if ($workPackageId <= 0) {
                continue;
            }
            $tickets = $ticketMap[$workPackageId] ?? [];
            $details['openproject_url'] = $this->workPackageUrl($workPackageId);
            $details['ticket_ids'] = $tickets;
            $details['responsible'] = trim((string) ($details['responsible'] ?? ''));
            $this->saveMonitor($userId, $workPackageId, $details, $tickets, $now);
            $this->createNotificationIfNeeded($userId, $workPackageId, $details, $tickets, $now);
        }

        return count($workPackages);
    }

    public function userRows(int $userId): array
    {
        return $this->rows(['users_id' => $userId, 'is_open' => 1]);
    }

    /** Consolidates latest observations from every personal-token query. */
    public function consolidatedRows(string $responsible = ''): array
    {
        $unique = [];
        foreach ($this->rows(['is_open' => 1], 'date_mod DESC, id DESC') as $row) {
            $workPackageId = (int) ($row['openproject_work_package_id'] ?? 0);
            if ($workPackageId <= 0 || isset($unique[$workPackageId])) {
                continue;
            }
            if ($responsible !== '' && (string) ($row['responsible_name'] ?? '') !== $responsible) {
                continue;
            }
            $unique[$workPackageId] = $row;
        }
        return array_values($unique);
    }

    public function responsibleOptions(): array
    {
        $names = [];
        foreach ($this->rows(['is_open' => 1]) as $row) {
            $name = trim((string) ($row['responsible_name'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }
        natcasesort($names);
        return $names;
    }

    public function statusTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $status = trim((string) ($row['status_name'] ?? '')) ?: 'Sem status';
            $totals[$status] = ($totals[$status] ?? 0) + 1;
        }
        uksort($totals, 'strnatcasecmp');
        return $totals;
    }

    public function notifications(int $userId, bool $onlyUnread = false, int $limit = 100): array
    {
        $where = ['users_id' => $userId];
        if ($onlyUnread) {
            $where['is_read'] = 0;
        }
        $rows = [];
        foreach ($this->db->request([
            'FROM' => self::NOTIFICATIONS,
            'WHERE' => $where,
            'ORDER' => 'date_creation DESC, id DESC',
            'LIMIT' => max(1, min($limit, 200)),
        ]) as $row) {
            $details = json_decode((string) ($row['details_json'] ?? '{}'), true);
            $row['details'] = is_array($details) ? $details : [];
            $rows[] = $row;
        }
        return $rows;
    }

    public function unreadCount(int $userId): int
    {
        // The header only renders a visual counter. Keeping this query in the
        // same builder shape as the notification feed avoids DB abstraction
        // differences around aggregate aliases across supported GLPI releases.
        return count($this->notifications($userId, true, 200));
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->db->update(self::NOTIFICATIONS, ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')], [
            'id' => $notificationId,
            'users_id' => $userId,
        ]);
    }

    private function saveMonitor(int $userId, int $workPackageId, array $details, array $tickets, string $now): void
    {
        $values = [
            'users_id' => $userId,
            'openproject_work_package_id' => $workPackageId,
            'responsible_name' => mb_substr((string) ($details['responsible'] ?? ''), 0, 255),
            'status_name' => mb_substr((string) ($details['status'] ?? ''), 0, 120),
            'is_open' => 1,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ticket_ids_json' => json_encode(array_values($tickets), JSON_THROW_ON_ERROR),
            'last_queried_at' => $now,
            'date_mod' => $now,
        ];
        $existing = null;
        foreach ($this->db->request([
            'FROM' => self::MONITORS,
            'WHERE' => ['users_id' => $userId, 'openproject_work_package_id' => $workPackageId],
            'LIMIT' => 1,
        ]) as $row) {
            $existing = $row;
            break;
        }
        if ($existing === null) {
            $values['date_creation'] = $now;
            $this->db->insert(self::MONITORS, $values);
            return;
        }
        $this->db->update(self::MONITORS, $values, ['id' => (int) $existing['id']]);
    }

    private function createNotificationIfNeeded(int $userId, int $workPackageId, array $details, array $tickets, string $now): void
    {
        $status = trim((string) ($details['status'] ?? '')) ?: 'Sem status';
        $updatedAt = trim((string) ($details['updated_at'] ?? ''));
        $fingerprint = hash('sha256', $workPackageId . '|' . $status . '|' . $updatedAt);
        foreach ($this->db->request([
            'FROM' => self::NOTIFICATIONS,
            'WHERE' => ['users_id' => $userId, 'openproject_work_package_id' => $workPackageId, 'fingerprint' => $fingerprint],
            'LIMIT' => 1,
        ]) as $_row) {
            return;
        }
        $priority = $this->isAttentionStatus($status) ? 'warning' : 'info';
        $subject = trim((string) ($details['subject'] ?? ''));
        $message = 'WP #' . $workPackageId . ' — ' . $subject . ' | Status: ' . $status;
        if ($tickets !== []) {
            $message .= ' | Chamado(s): #' . implode(', #', $tickets);
        }
        $this->db->insert(self::NOTIFICATIONS, [
            'users_id' => $userId,
            'openproject_work_package_id' => $workPackageId,
            'fingerprint' => $fingerprint,
            'priority' => $priority,
            'title' => $priority === 'warning' ? 'Atenção: Work Package aberta' : 'Work Package aberta identificada',
            'message' => mb_substr($message, 0, 2000),
            'details_json' => json_encode($details + ['ticket_ids' => $tickets], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'is_read' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ]);
    }

    private function rows(array $where, string $order = 'date_mod DESC, id DESC'): array
    {
        $rows = [];
        foreach ($this->db->request(['FROM' => self::MONITORS, 'WHERE' => $where, 'ORDER' => $order]) as $row) {
            $details = json_decode((string) ($row['details_json'] ?? '{}'), true);
            $row['details'] = is_array($details) ? $details : [];
            $ticketIds = json_decode((string) ($row['ticket_ids_json'] ?? '[]'), true);
            $row['ticket_ids'] = is_array($ticketIds) ? array_values(array_filter(array_map('intval', $ticketIds))) : [];
            $rows[] = $row;
        }
        return $rows;
    }

    /** Finds all ticket references, including legacy/manual references. */
    private function ticketMap(array $workPackageIds): array
    {
        $wanted = array_fill_keys(array_filter(array_map('intval', $workPackageIds)), true);
        if ($wanted === []) {
            return [];
        }
        $map = array_fill_keys(array_keys($wanted), []);
        try {
            foreach ($this->db->request(['FROM' => 'glpi_plugin_demandas_links']) as $row) {
                $workPackageId = (int) ($row['openproject_work_package_id'] ?? 0);
                $ticketId = (int) ($row['tickets_id'] ?? 0);
                if (isset($wanted[$workPackageId]) && $ticketId > 0) {
                    $map[$workPackageId][$ticketId] = $ticketId;
                }
            }
        } catch (\Throwable) {
            // Legacy links are complementary to the live OpenProject query.
        }

        $this->collectFollowupReferences($map, $wanted);
        $this->collectActivityDevOpsReferences($map, $wanted);
        foreach ($map as $workPackageId => $ticketIds) {
            $map[$workPackageId] = array_values($ticketIds);
            sort($map[$workPackageId]);
        }
        return $map;
    }

    private function collectFollowupReferences(array &$map, array $wanted): void
    {
        try {
            foreach ($this->db->request([
                'SELECT' => ['items_id', 'content'],
                'FROM' => 'glpi_itilfollowups',
                'WHERE' => ['itemtype' => Ticket::class],
            ]) as $row) {
                $ticketId = (int) ($row['items_id'] ?? 0);
                if ($ticketId <= 0) {
                    continue;
                }
                foreach ($this->extractWorkPackageIds((string) ($row['content'] ?? '')) as $workPackageId) {
                    if (isset($wanted[$workPackageId])) {
                        $map[$workPackageId][$ticketId] = $ticketId;
                    }
                }
            }
        } catch (\Throwable) {
            // Some old GLPI schemas use different followup storage; the
            // plugin's direct links and DevOps field remain available.
        }
    }

    private function collectActivityDevOpsReferences(array &$map, array $wanted): void
    {
        foreach (FieldsClassificationProvider::workPackageLinkFields() as $field) {
            try {
                foreach ($this->db->request([
                    'SELECT' => [(string) $field['ticket_key'], (string) $field['value_field']],
                    'FROM' => (string) $field['table'],
                    'WHERE' => [(string) $field['itemtype_field'] => Ticket::class],
                ]) as $row) {
                    $ticketId = (int) ($row[(string) $field['ticket_key']] ?? 0);
                    if ($ticketId <= 0) {
                        continue;
                    }
                    foreach ($this->extractWorkPackageIds((string) ($row[(string) $field['value_field']] ?? '')) as $workPackageId) {
                        if (isset($wanted[$workPackageId])) {
                            $map[$workPackageId][$ticketId] = $ticketId;
                        }
                    }
                }
            } catch (\Throwable) {
                // An inactive Fields container must not abort monitoring.
            }
        }
    }

    private function extractWorkPackageIds(string $value): array
    {
        preg_match_all('~(?:https?://[^\\s"\'<>]+)?(?:/|%2F)work_packages(?:/|%2F)([0-9]+)~i', html_entity_decode($value), $matches);
        return array_values(array_unique(array_filter(array_map('intval', $matches[1] ?? []))));
    }

    private function workPackageUrl(int $workPackageId): string
    {
        return rtrim((string) Config::get('openproject_external_url', ''), '/') . '/work_packages/' . $workPackageId;
    }

    private function isAttentionStatus(string $status): bool
    {
        $status = mb_strtolower($status);
        return str_contains($status, 'novo') || str_contains($status, 'new') || str_contains($status, 'especific');
    }
}
