<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use RuntimeException;
use Ticket;

/**
 * Evaluates only data already stored in GLPI. It deliberately does not call
 * OpenProject: a verification is deterministic, auditable and safe to run in
 * the production interface without creating hidden external traffic.
 */
final class OperationalHealthService
{
    private const FINDINGS = 'glpi_plugin_demandas_operational_findings';
    private const RUNS = 'glpi_plugin_demandas_operational_runs';
    private const ACTIONS = 'glpi_plugin_demandas_operational_actions';
    private const RULE_TICKET_WITHOUT_WP = 'ticket_without_wp';
    private const RULE_INITIAL_STATUS = 'wp_initial_status_overdue';
    private const RULE_STALE_SYNC = 'wp_sync_stale';
    private const RULES = [
        self::RULE_TICKET_WITHOUT_WP => 'Chamado elegível sem Work Package',
        self::RULE_INITIAL_STATUS => 'Work Package parada no status inicial',
        self::RULE_STALE_SYNC => 'Work Package sem sincronização recente',
    ];

    private object $db;

    public function __construct()
    {
        $this->db = DBConnection::getReadConnection();
    }

    public static function rules(): array
    {
        return self::RULES;
    }

    /** Runs all local rules within the active entity scope. */
    public function run(int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            throw new RuntimeException('Usuário inválido para executar a verificação operacional.');
        }

        $now = date('Y-m-d H:i:s');
        $entities = $this->activeEntities();
        $this->db->insert(self::RUNS, [
            'users_id' => $actorUserId,
            'scope_entities_json' => json_encode($entities, JSON_THROW_ON_ERROR),
            'status' => 'running',
            'started_at' => $now,
            'date_creation' => $now,
        ]);
        $runId = (int) $this->db->insertId();

        try {
            $candidates = $this->candidates($entities);
            $seen = [];
            foreach ($candidates as $candidate) {
                $fingerprint = (string) $candidate['fingerprint'];
                $seen[$fingerprint] = true;
                $this->upsertFinding($candidate, $runId, $now);
            }
            $this->resolveMissing($entities, array_keys($seen), $runId, $now);
            $this->db->update(self::RUNS, [
                'status' => 'success',
                'findings_count' => count($candidates),
                'finished_at' => date('Y-m-d H:i:s'),
            ], ['id' => $runId]);
            return ['run_id' => $runId, 'findings_count' => count($candidates)];
        } catch (\Throwable $exception) {
            $this->db->update(self::RUNS, [
                'status' => 'error',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => date('Y-m-d H:i:s'),
            ], ['id' => $runId]);
            throw $exception;
        }
    }

    /** Returns only findings whose ticket remains readable by the active user. */
    public function findings(array $input = []): array
    {
        $filters = $this->filters($input);
        $entities = array_fill_keys($this->activeEntities(), true);
        $rows = [];
        foreach ($this->db->request([
            'FROM' => self::FINDINGS,
            'ORDER' => 'severity DESC, last_detected_at DESC, id DESC',
        ]) as $row) {
            if (!isset($entities[(int) ($row['entities_id'] ?? -1)])) {
                continue;
            }
            if (!$this->ticketReadable((int) ($row['tickets_id'] ?? 0))) {
                continue;
            }
            if ($filters['state'] !== '' && (string) ($row['state'] ?? '') !== $filters['state']) {
                continue;
            }
            if ($filters['severity'] !== '' && (string) ($row['severity'] ?? '') !== $filters['severity']) {
                continue;
            }
            if ($filters['rule_code'] !== '' && (string) ($row['rule_code'] ?? '') !== $filters['rule_code']) {
                continue;
            }
            $row['evidence'] = $this->decode((string) ($row['evidence_json'] ?? ''));
            $rows[] = $row;
        }
        return $rows;
    }

    public function summary(array $rows): array
    {
        $summary = ['open' => 0, 'acknowledged' => 0, 'ignored' => 0, 'high' => 0, 'medium' => 0];
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            $severity = (string) ($row['severity'] ?? '');
            if (isset($summary[$state])) {
                $summary[$state]++;
            }
            if (isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }
        return $summary;
    }

    /** Latest audited action for each visible finding, keyed by finding id. */
    public function latestActions(array $findingIds): array
    {
        $wanted = array_fill_keys(array_filter(array_map('intval', $findingIds)), true);
        $latest = [];
        if ($wanted === []) {
            return $latest;
        }
        foreach ($this->db->request(['FROM' => self::ACTIONS, 'ORDER' => 'date_creation DESC, id DESC']) as $row) {
            $findingId = (int) ($row['operational_findings_id'] ?? 0);
            if (isset($wanted[$findingId]) && !isset($latest[$findingId])) {
                $latest[$findingId] = $row;
            }
        }
        return $latest;
    }

    public function act(int $findingId, string $action, int $actorUserId): void
    {
        $finding = $this->findingForCurrentScope($findingId);
        if ($finding === null) {
            throw new RuntimeException('A pendência solicitada não está disponível no seu escopo atual.');
        }
        $map = [
            'acknowledge' => ['acknowledged', 'Pendência reconhecida'],
            'reopen' => ['open', 'Pendência reaberta'],
            'ignore' => ['ignored', 'Pendência ignorada por sete dias'],
        ];
        if (!isset($map[$action])) {
            throw new RuntimeException('Ação de pendência inválida.');
        }
        [$state, $label] = $map[$action];
        $now = date('Y-m-d H:i:s');
        $values = ['state' => $state, 'date_mod' => $now];
        if ($action === 'ignore') {
            $values['ignored_until'] = date('Y-m-d H:i:s', strtotime('+7 days'));
        } else {
            $values['ignored_until'] = null;
        }
        $this->db->update(self::FINDINGS, $values, ['id' => $findingId]);
        $this->db->insert(self::ACTIONS, [
            'operational_findings_id' => $findingId,
            'users_id' => $actorUserId,
            'action' => $action,
            'details_json' => json_encode(['label' => $label], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'date_creation' => $now,
        ]);
    }

    private function candidates(array $entities): array
    {
        if ($entities === []) {
            return [];
        }
        $entitySql = implode(',', array_map('intval', $entities));
        $sql = "SELECT t.id AS ticket_id, t.name AS ticket_name, t.status AS ticket_status, t.date AS ticket_date, t.entities_id,
                    l.id AS link_id, l.openproject_work_package_id, l.openproject_status, l.last_synced_at,
                    l.date_creation AS link_created_at, l.work_package_details_json
                FROM glpi_tickets t
                LEFT JOIN glpi_plugin_demandas_links l ON l.tickets_id = t.id
                WHERE t.is_deleted = 0 AND t.entities_id IN ({$entitySql})
                ORDER BY t.id DESC, l.id DESC";
        $byTicket = [];
        foreach ($this->db->doQuery($sql) as $row) {
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            if ($ticketId <= 0 || !$this->ticketReadable($ticketId)) {
                continue;
            }
            $byTicket[$ticketId]['ticket'] = $row;
            if ((int) ($row['link_id'] ?? 0) > 0) {
                $byTicket[$ticketId]['links'][] = $row;
            }
        }

        $candidates = [];
        foreach ($byTicket as $item) {
            $ticket = (array) ($item['ticket'] ?? []);
            $links = (array) ($item['links'] ?? []);
            $ticketIsOpen = !in_array((int) ($ticket['ticket_status'] ?? 0), [5, 6], true);
            if ($ticketIsOpen && $links === [] && $this->ticketNeedsWorkPackage((int) $ticket['ticket_id'])) {
                $candidates[] = $this->candidate(self::RULE_TICKET_WITHOUT_WP, 'high', $ticket, 0, [
                    'ticket_name' => mb_substr((string) ($ticket['ticket_name'] ?? ''), 0, 255),
                    'reason' => 'A classificação configurada permite User Story, Épico ou Bug, mas não há vínculo local de Work Package.',
                ]);
            }
            foreach ($links as $link) {
                $status = trim((string) ($link['openproject_status'] ?? ''));
                $workPackageId = (int) ($link['openproject_work_package_id'] ?? 0);
                if ($workPackageId <= 0 || $this->looksClosed($status)) {
                    continue;
                }
                $ageDays = $this->ageDays((string) ($link['link_created_at'] ?? ''));
                if ($this->isInitialStatus($status) && $ageDays >= $this->initialDays()) {
                    $candidates[] = $this->candidate(self::RULE_INITIAL_STATUS, 'high', $ticket, $workPackageId, [
                        'ticket_name' => mb_substr((string) ($ticket['ticket_name'] ?? ''), 0, 255),
                        'wp_status' => $status,
                        'age_days' => $ageDays,
                        'limit_days' => $this->initialDays(),
                    ]);
                }
                $syncAge = $this->ageDays((string) ($link['last_synced_at'] ?? $link['link_created_at'] ?? ''));
                if ($syncAge >= $this->staleDays()) {
                    $candidates[] = $this->candidate(self::RULE_STALE_SYNC, 'medium', $ticket, $workPackageId, [
                        'ticket_name' => mb_substr((string) ($ticket['ticket_name'] ?? ''), 0, 255),
                        'wp_status' => $status,
                        'sync_age_days' => $syncAge,
                        'limit_days' => $this->staleDays(),
                    ]);
                }
            }
        }
        return $candidates;
    }

    private function candidate(string $rule, string $severity, array $ticket, int $wpId, array $evidence): array
    {
        $ticketId = (int) ($ticket['ticket_id'] ?? 0);
        return [
            'rule_code' => $rule,
            'severity' => $severity,
            'tickets_id' => $ticketId,
            'entities_id' => (int) ($ticket['entities_id'] ?? 0),
            'openproject_work_package_id' => $wpId ?: null,
            'fingerprint' => hash('sha256', $rule . '|' . $ticketId . '|' . $wpId),
            'evidence' => $evidence,
        ];
    }

    private function upsertFinding(array $candidate, int $runId, string $now): void
    {
        $existing = null;
        foreach ($this->db->request(['FROM' => self::FINDINGS, 'WHERE' => ['fingerprint' => $candidate['fingerprint']], 'LIMIT' => 1]) as $row) {
            $existing = $row;
            break;
        }
        $values = [
            'rule_code' => $candidate['rule_code'],
            'severity' => $candidate['severity'],
            'tickets_id' => $candidate['tickets_id'],
            'entities_id' => $candidate['entities_id'],
            'openproject_work_package_id' => $candidate['openproject_work_package_id'],
            'evidence_json' => json_encode($candidate['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'last_run_id' => $runId,
            'last_detected_at' => $now,
            'date_mod' => $now,
        ];
        if ($existing === null) {
            $values += ['fingerprint' => $candidate['fingerprint'], 'state' => 'open', 'first_detected_at' => $now, 'date_creation' => $now];
            $this->db->insert(self::FINDINGS, $values);
            return;
        }
        $ignoredUntil = strtotime((string) ($existing['ignored_until'] ?? ''));
        if ((string) ($existing['state'] ?? '') === 'resolved' || ((string) ($existing['state'] ?? '') === 'ignored' && ($ignoredUntil === false || $ignoredUntil <= time()))) {
            $values['state'] = 'open';
            $values['ignored_until'] = null;
        }
        $this->db->update(self::FINDINGS, $values, ['id' => (int) $existing['id']]);
    }

    private function resolveMissing(array $entities, array $seen, int $runId, string $now): void
    {
        $entityMap = array_fill_keys($entities, true);
        $seenMap = array_fill_keys($seen, true);
        foreach ($this->db->request(['FROM' => self::FINDINGS]) as $finding) {
            if (!isset($entityMap[(int) ($finding['entities_id'] ?? -1)]) || isset($seenMap[(string) ($finding['fingerprint'] ?? '')])) {
                continue;
            }
            if (in_array((string) ($finding['state'] ?? ''), ['open', 'acknowledged', 'ignored'], true)) {
                $this->db->update(self::FINDINGS, ['state' => 'resolved', 'last_run_id' => $runId, 'date_mod' => $now], ['id' => (int) $finding['id']]);
            }
        }
    }

    private function findingForCurrentScope(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $entities = array_fill_keys($this->activeEntities(), true);
        foreach ($this->db->request(['FROM' => self::FINDINGS, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
            return isset($entities[(int) ($row['entities_id'] ?? -1)]) && $this->ticketReadable((int) ($row['tickets_id'] ?? 0)) ? $row : null;
        }
        return null;
    }

    private function activeEntities(): array
    {
        return array_values(array_filter(array_map('intval', \Session::getActiveEntities()), static fn(int $id): bool => $id >= 0));
    }

    private function ticketReadable(int $ticketId): bool
    {
        $ticket = new Ticket();
        return $ticketId > 0 && $ticket->getFromDB($ticketId) && $ticket->can($ticketId, READ);
    }

    private function ticketNeedsWorkPackage(int $ticketId): bool
    {
        if ((string) (ClassificationPolicy::source()['mode'] ?? 'none') === 'none') {
            return false;
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return false;
        }
        try {
            $types = ClassificationPolicy::filterTypes($ticket, [
                ['name' => 'User Story'], ['name' => 'Épico'], ['name' => 'Bug'],
            ]);
            return $types !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function isInitialStatus(string $status): bool
    {
        return in_array($this->normal($status), array_map($this->normal(...), $this->initialStatuses()), true);
    }

    private function initialStatuses(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Config::get('operational_health_initial_statuses', 'Novo,Em especificação')))));
    }

    private function initialDays(): int
    {
        return max(1, min(365, (int) Config::get('operational_health_initial_days', '7')));
    }

    private function staleDays(): int
    {
        return max(1, min(365, (int) Config::get('operational_health_stale_days', '7')));
    }

    private function looksClosed(string $status): bool
    {
        $status = $this->normal($status);
        return str_contains($status, 'fech') || str_contains($status, 'conclu') || str_contains($status, 'cancel') || str_contains($status, 'resolvid');
    }

    private function ageDays(string $value): int
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? 9999 : max(0, (int) floor((time() - $timestamp) / 86400));
    }

    private function filters(array $input): array
    {
        return [
            'state' => in_array((string) ($input['state'] ?? ''), ['open', 'acknowledged', 'ignored', 'resolved'], true) ? (string) $input['state'] : '',
            'severity' => in_array((string) ($input['severity'] ?? ''), ['high', 'medium'], true) ? (string) $input['severity'] : '',
            'rule_code' => array_key_exists((string) ($input['rule_code'] ?? ''), self::RULES) ? (string) $input['rule_code'] : '',
        ];
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normal(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
