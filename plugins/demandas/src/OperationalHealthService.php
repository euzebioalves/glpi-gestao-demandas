<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

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
        'wp_data_quality' => 'Informação insuficiente para avaliar a Work Package',
    ];

    private object $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB; // Reads and atomic writes must use the same primary snapshot.
    }

    public static function rules(): array
    {
        return self::RULES;
    }

    /** Runs all local rules within the active entity scope. */
    public function run(int $actorUserId): array
    {
        $this->checkOperation($actorUserId);
        if ($actorUserId <= 0) {
            throw new RuntimeException('Usuário inválido para executar a verificação operacional.');
        }

        return OperationGuard::run('operational-health', fn() => $this->runLocked($actorUserId));
    }

    private function runLocked(int $actorUserId): array
    {
        $started = microtime(true);
        $now = date('Y-m-d H:i:s');
        $entities = $this->activeEntities();
        OperationGuard::write($this->db->insert(self::RUNS, [
            'users_id' => $actorUserId,
            'scope_entities_json' => json_encode($entities, JSON_THROW_ON_ERROR),
            'status' => 'running',
            'started_at' => $now,
            'date_creation' => $now,
        ]));
        $runId = (int) $this->db->insertId();

        try {
            return OperationGuard::transaction(function () use ($entities, $runId, $now, $started): array {
                [$candidates, $evaluatedTickets, $incomplete] = $this->candidates($entities);
                $seen = [];
                foreach ($candidates as $candidate) {
                    $seen[] = $candidate['fingerprint'];
                    $this->upsertFinding($candidate, $runId, $now);
                }
                // Missing evidence in any evaluated ticket makes this entire round partial.
                if (!$incomplete) $this->resolveMissing($entities, $evaluatedTickets, $seen, $runId, $now);
                $metrics = ['evaluated_tickets'=>count($evaluatedTickets), 'incomplete_links'=>$incomplete,
                    'duration_ms'=>(int) round((microtime(true)-$started)*1000), 'external_requests'=>0];
                $status = $incomplete ? 'partial' : 'success';
                OperationGuard::write($this->db->update(self::RUNS, [
                    'status'=>$status, 'findings_count'=>count($candidates),
                    'metrics_json'=>json_encode($metrics, JSON_THROW_ON_ERROR), 'finished_at'=>date('Y-m-d H:i:s'),
                ], ['id'=>$runId]));
                return ['run_id'=>$runId, 'findings_count'=>count($candidates), 'status'=>$status] + $metrics;
            });
        } catch (\Throwable $exception) {
            $this->db->update(self::RUNS, [
                'status' => 'error',
                'error_message' => mb_substr(IntegrationSecurity::message($exception), 0, 2000),
                'finished_at' => date('Y-m-d H:i:s'),
            ], ['id' => $runId]);
            throw $exception;
        }
    }

    /** Returns only findings whose ticket remains readable by the active user. */
    public function findings(array $input = []): array
    {
        $this->checkView();
        $filters = $this->filters($input);
        $entities = array_fill_keys($this->activeEntities(), true);
        $where = ['entities_id'=>array_keys($entities) ?: [-1]];
        foreach ($filters as $key=>$value) if ($value !== '') {
            if ($key === 'state' && $value === 'active') $where['NOT'] = ['state'=>'resolved'];
            else $where[$key] = $value;
        }
        $rows = [];
        foreach ($this->db->request([
            'FROM' => self::FINDINGS,
            'WHERE' => $where,
            'ORDER' => 'severity DESC, last_detected_at DESC, id DESC',
        ]) as $row) {
            if (!isset($entities[(int) ($row['entities_id'] ?? -1)])) {
                continue;
            }
            if (!$this->ticketReadable((int) ($row['tickets_id'] ?? 0))) {
                continue;
            }
            if ($filters['state'] !== '' && $filters['state'] !== 'active' && (string) ($row['state'] ?? '') !== $filters['state']) {
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
            if ($state !== 'resolved' && isset($summary[$severity])) {
                $summary[$severity]++;
            }
        }
        return $summary;
    }

    /** Latest audited action for each visible finding, keyed by finding id. */
    public function latestActions(array $findingIds): array
    {
        $this->checkView();
        $wanted = array_fill_keys(array_filter(array_map('intval', $findingIds)), true);
        foreach (array_keys($wanted) as $id) if ($this->findingForCurrentScope($id) === null) unset($wanted[$id]);
        $latest = [];
        if ($wanted === []) {
            return $latest;
        }
        foreach ($this->db->request(['FROM' => self::ACTIONS, 'WHERE'=>['operational_findings_id'=>array_keys($wanted)], 'ORDER' => 'date_creation DESC, id DESC']) as $row) {
            $findingId = (int) ($row['operational_findings_id'] ?? 0);
            if (isset($wanted[$findingId]) && !isset($latest[$findingId])) {
                $latest[$findingId] = $row;
            }
        }
        return $latest;
    }

    public function history(int $findingId): array
    {
        $this->checkView();
        if ($this->findingForCurrentScope($findingId) === null) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        return array_values(iterator_to_array($this->db->request([
            'FROM'=>self::ACTIONS, 'WHERE'=>['operational_findings_id'=>$findingId],
            'ORDER'=>['date_creation DESC','id DESC'], 'LIMIT'=>100,
        ])));
    }

    public function detail(int $findingId): array
    {
        $this->checkView();
        return $this->findingForCurrentScope($findingId) ?? throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }

    /** Only this user's runs: totals must not disclose another user's broader ticket scope. */
    public function recentRuns(): array
    {
        $this->checkView();
        return array_values(iterator_to_array($this->db->request([
            'FROM'=>self::RUNS, 'WHERE'=>['users_id'=>(int) \Session::getLoginUserID()],
            'ORDER'=>'id DESC', 'LIMIT'=>5,
        ])));
    }

    private function checkView(): void
    {
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        Profile::checkRight(Profile::VIEW_OPERATIONAL_HEALTH);
    }

    public function act(int $findingId, string $action, int $actorUserId, array $input = []): void
    {
        $this->checkOperation($actorUserId);
        OperationGuard::run('operational-health', fn() => OperationGuard::transaction(
            fn() => $this->actLocked($findingId, $action, $actorUserId, $input)
        ));
    }

    private function actLocked(int $findingId, string $action, int $actorUserId, array $input): void
    {
        $finding = $this->findingForCurrentScope($findingId);
        if ($finding === null) {
            throw new RuntimeException('A pendência solicitada não está disponível no seu escopo atual.');
        }
        $map = [
            'acknowledge' => ['acknowledged', 'Pendência reconhecida'],
            'reopen' => ['open', 'Pendência reaberta'],
            'followup' => [(string) $finding['state'], 'Acompanhamento atualizado'],
            'ignore' => ['ignored', 'Pendência ignorada por sete dias'],
        ];
        if (!isset($map[$action])) {
            throw new RuntimeException('Ação de pendência inválida.');
        }
        [$state, $label] = $map[$action];
        $now = date('Y-m-d H:i:s');
        $values = ['state' => $state, 'date_mod' => $now];
        foreach (['note','due_date','take_ownership'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new RuntimeException('Dados de acompanhamento inválidos.');
        }
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 2000 || ($action === 'ignore' && mb_strlen($note) < 10)) {
            throw new RuntimeException('Informe um motivo de 10 a 2.000 caracteres para adiar; observações aceitam até 2.000 caracteres.');
        }
        if ($action === 'followup') {
            $due = trim((string) ($input['due_date'] ?? ''));
            $date = $due === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if ($due !== '' && (!$date || $date->format('Y-m-d') !== $due || $due < date('Y-m-d') || $due > date('Y-m-d', strtotime('+365 days')))) {
                throw new RuntimeException('Informe um prazo válido entre hoje e os próximos 365 dias.');
            }
            $values += ['due_date'=>$due ?: null, 'treatment_note'=>$note];
            // Ownership can only be voluntarily assumed by the authenticated operator.
            if (($input['take_ownership'] ?? '') === '1') $values['owner_users_id'] = $actorUserId;
        }
        if ($action === 'ignore') {
            $values['ignored_until'] = date('Y-m-d H:i:s', strtotime('+7 days'));
        } elseif ($action !== 'followup') {
            $values['ignored_until'] = null;
        }
        OperationGuard::write($this->db->update(self::FINDINGS, $values, ['id' => $findingId]));
        OperationGuard::write($this->db->insert(self::ACTIONS, [
            'operational_findings_id' => $findingId,
            'users_id' => $actorUserId,
            'action' => $action,
            'details_json' => json_encode(['label' => $label, 'note'=>$note, 'before'=>array_intersect_key($finding, $values), 'after'=>$values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'date_creation' => $now,
        ]));
    }

    private function candidates(array $entities): array
    {
        if ($entities === []) {
            return [[], [], 0];
        }
        $query = [
            'SELECT'=>['glpi_tickets'=>['id AS ticket_id','name AS ticket_name','status AS ticket_status','date AS ticket_date','entities_id'],
                'glpi_plugin_demandas_links'=>['id AS link_id','openproject_work_package_id','openproject_status','last_synced_at','work_package_details_json']],
            'FROM'=>'glpi_tickets',
            'LEFT JOIN'=>['glpi_plugin_demandas_links'=>['ON'=>['glpi_plugin_demandas_links'=>'tickets_id','glpi_tickets'=>'id']]],
            'WHERE'=>['glpi_tickets.is_deleted'=>0,'glpi_tickets.entities_id'=>$entities],
            'ORDER'=>['glpi_tickets.id DESC','glpi_plugin_demandas_links.id DESC'],
        ];
        $byTicket = [];
        $readable = [];
        foreach ($this->db->request($query) as $row) {
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            $readable[$ticketId] ??= $this->ticketReadable($ticketId);
            if ($ticketId <= 0 || !$readable[$ticketId]) {
                continue;
            }
            $byTicket[$ticketId]['ticket'] = $row;
            if ((int) ($row['link_id'] ?? 0) > 0) {
                $byTicket[$ticketId]['links'][] = $row;
            }
        }

        $history = StageObservation::load($this->db, $byTicket);
        $incomplete = 0;
        $candidates = [];
        foreach ($byTicket as $item) {
            $ticket = (array) ($item['ticket'] ?? []);
            $links = (array) ($item['links'] ?? []);
            $ticketIsOpen = !in_array((int) ($ticket['ticket_status'] ?? 0), [Ticket::SOLVED, Ticket::CLOSED], true);
            if ($ticketIsOpen && $links === [] && $this->ticketNeedsWorkPackage((int) $ticket['ticket_id'])) {
                $candidates[] = $this->candidate(self::RULE_TICKET_WITHOUT_WP, 'high', $ticket, 0, [
                    'ticket_name' => mb_substr((string) ($ticket['ticket_name'] ?? ''), 0, 255),
                    'reason' => 'A classificação configurada permite User Story, Épico ou Bug, mas não há vínculo local de Work Package.',
                    'recommendation' => 'Confira Atividade DevOps e concilie uma WP existente; crie outra somente se necessário.',
                ]);
            }
            foreach ($links as $link) {
                $status = trim((string) ($link['openproject_status'] ?? ''));
                $workPackageId = (int) ($link['openproject_work_package_id'] ?? 0);
                if ($workPackageId <= 0) continue;
                $quality = IndicatorPolicy::freshness($link['last_synced_at'] ?? null, $this->staleDays());
                $state = $history[(int)$ticket['ticket_id']][$workPackageId] ?? ['status'=>null,'since'=>null];
                $since = $status !== '' && $state['status'] === $status ? $state['since'] : null;
                $ageDays = $since === null ? null : (int) floor((IndicatorPolicy::timestamp($link['last_synced_at']) - IndicatorPolicy::timestamp($since)) / 86400);
                $initial = IndicatorPolicy::initial($status, $this->initialStatuses());
                $missing = $state['failure'] ?? (!IndicatorPolicy::statusKnown($status) ? 'unknown_status' : ($initial && $ageDays === null ? 'unknown_history' : $quality));
                $evidence = [
                    'ticket_name'=>mb_substr((string)$ticket['ticket_name'],0,255), 'wp_status'=>$status,
                    'last_synced_at'=>$link['last_synced_at'], 'observed_since'=>$since,
                    'age_days'=>$ageDays, 'quality'=>$missing,
                    'reason'=>IndicatorPolicy::qualityLabel($missing),
                    'recommendation'=>'Confira o vínculo e sincronize a WP pela Evolução da Demanda. Depois execute novamente a verificação.',
                ];
                if ($quality !== 'current' || $missing !== 'current') $incomplete++;
                if (!in_array($missing, ['current','stale'], true)) {
                    $candidates[] = $this->candidate('wp_data_quality', 'medium', $ticket, $workPackageId, $evidence);
                }
                if ($initial && $ageDays !== null && $ageDays >= $this->initialDays()) {
                    $candidates[] = $this->candidate(self::RULE_INITIAL_STATUS, 'high', $ticket, $workPackageId,
                        array_replace($evidence, ['limit_days'=>$this->initialDays(), 'recommendation'=>'Revisar a etapa com a equipe; o tempo medido termina na última sincronização.']));
                }
                // Do not infer remote closure from fragments such as "conclu" or "fech".
                // Even an Entregue snapshot can become stale and needs confirmation.
                if ($quality !== 'current') {
                    $candidates[] = $this->candidate(self::RULE_STALE_SYNC, 'medium', $ticket, $workPackageId,
                        $evidence + ['limit_days'=>$this->staleDays()]);
                }
            }
        }
        // Include evaluated tickets without findings: only these can resolve old occurrences.
        return [$candidates, array_keys($byTicket), $incomplete];
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
            OperationGuard::write($this->db->insert(self::FINDINGS, $values));
            return;
        }
        $ignoredUntil = strtotime((string) ($existing['ignored_until'] ?? ''));
        if ((string) ($existing['state'] ?? '') === 'resolved' || ((string) ($existing['state'] ?? '') === 'ignored' && ($ignoredUntil === false || $ignoredUntil <= time()))) {
            $values['state'] = 'open';
            $values['ignored_until'] = null;
        }
        OperationGuard::write($this->db->update(self::FINDINGS, $values, ['id' => (int) $existing['id']]));
        if (isset($values['state'])) {
            OperationGuard::write($this->db->insert(self::ACTIONS, [
                'operational_findings_id'=>(int)$existing['id'], 'users_id'=>(int)\Session::getLoginUserID(),
                'action'=>'reopen', 'date_creation'=>$now,
                'details_json'=>json_encode(['label'=>'Reaberta pela verificação', 'run_id'=>$runId,
                    'reason'=>'Regra novamente identificada ou adiamento expirado.', 'previous_state'=>$existing['state']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]));
        }
    }

    private function resolveMissing(array $entities, array $evaluatedTickets, array $seen, int $runId, string $now): void
    {
        if ($entities === [] || $evaluatedTickets === []) {
            return;
        }
        $seenMap = array_fill_keys($seen, true);
        foreach ($this->db->request(['FROM' => self::FINDINGS, 'WHERE' => [
            'entities_id' => $entities,
            'tickets_id' => $evaluatedTickets,
            'state' => ['open', 'acknowledged', 'ignored'],
        ]]) as $finding) {
            if (isset($seenMap[(string) ($finding['fingerprint'] ?? '')]) || !$this->ticketReadable((int) $finding['tickets_id'])) {
                continue;
            }
            if (in_array((string) ($finding['state'] ?? ''), ['open', 'acknowledged', 'ignored'], true)) {
                OperationGuard::write($this->db->update(self::FINDINGS, ['state' => 'resolved', 'last_run_id' => $runId, 'date_mod' => $now], ['id' => (int) $finding['id']]));
                OperationGuard::write($this->db->insert(self::ACTIONS, ['operational_findings_id'=>(int)$finding['id'], 'users_id'=>(int) \Session::getLoginUserID(), 'action'=>'resolved', 'date_creation'=>$now, 'details_json'=>json_encode(['run_id'=>$runId, 'reason'=>'Regra ausente na avaliação local completa do chamado acessível.', 'previous_evidence'=>$this->decode((string)$finding['evidence_json'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]));
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
        return $ticketId > 0 && $ticket->getFromDB($ticketId)
            && empty($ticket->fields['is_deleted'])
            && in_array((int)$ticket->fields['entities_id'], $this->activeEntities(), true)
            && $ticket->can($ticketId, READ);
    }

    private function ticketNeedsWorkPackage(int $ticketId): bool
    {
        if ((string) (ClassificationPolicy::source()['mode'] ?? 'none') === 'none') {
            return false;
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            throw new RuntimeException('Não foi possível concluir a avaliação dos chamados. Nenhuma pendência será resolvida por esta execução.');
        }
        try {
            $types = ClassificationPolicy::filterTypes($ticket, [
                ['name' => 'User Story'], ['name' => 'Épico'], ['name' => 'Bug'],
            ]);
            return $types !== [];
        } catch (\Throwable $exception) {
            throw new RuntimeException('Não foi possível avaliar a classificação dos chamados. Revise a configuração antes de executar novamente.', 0, $exception);
        }
    }

    private function checkOperation(int $actorUserId): void
    {
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        Profile::checkRight(Profile::VIEW_OPERATIONAL_HEALTH);
        Profile::checkRight(Profile::MANAGE_OPERATIONAL_HEALTH);
        if ($actorUserId <= 0 || $actorUserId !== (int) \Session::getLoginUserID()) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
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

    private function filters(array $input): array
    {
        return [
            'state' => in_array((string) ($input['state'] ?? ''), ['active', 'open', 'acknowledged', 'ignored', 'resolved'], true) ? (string) $input['state'] : '',
            'severity' => in_array((string) ($input['severity'] ?? ''), ['high', 'medium'], true) ? (string) $input['severity'] : '',
            'rule_code' => array_key_exists((string) ($input['rule_code'] ?? ''), self::RULES) ? (string) $input['rule_code'] : '',
        ];
    }

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

}
