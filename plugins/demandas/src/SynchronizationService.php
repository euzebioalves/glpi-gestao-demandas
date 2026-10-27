<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;

final class SynchronizationService
{
    private const FOLLOWUP_PENDING = 'Fase sincronizada. Há acompanhamento pendente; repita a sincronização para tentar publicá-lo sem duplicação.';
    public function synchronizeByWorkPackage(int $workPackageId, string $source = 'webhook'): array
    {
        $links = TicketDemand::findAllByWorkPackage($workPackageId);
        if ($links === []) {
            throw new RuntimeException('A Work Package recebida não possui vínculo no GLPI.');
        }

        $results = [];
        $failed = false;
        foreach ($links as $link) {
            try { $results[] = $this->synchronizeLink($link, $source); }
            catch (\Throwable) { $failed = true; }
        }
        if ($failed) throw new RuntimeException('Não foi possível sincronizar todos os vínculos da Work Package. A operação pode ser repetida.');
        return $results[0] + ['links_count' => count($results)];
    }

    public function synchronize(int $ticketId, string $source = 'manual'): array
    {
        $links = TicketDemand::findAllByTicket($ticketId);
        if ($links === []) {
            throw new RuntimeException('Este ticket não possui Work Package vinculada.');
        }

        $results = [];
        $errors = [];
        foreach ($links as $link) {
            try {
                $results[] = $this->synchronizeLink($link, $source);
            } catch (\Throwable $exception) {
                $errors[] = sprintf(
                    '#%d: %s',
                    (int) ($link['openproject_work_package_id'] ?? 0),
                    IntegrationSecurity::message($exception)
                );
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Falha ao sincronizar: ' . implode(' | ', $errors));
        }
        return ['ticket_id' => $ticketId, 'work_packages' => $results];
    }

    private function synchronizeLink(array $link, string $source): array
    {
        return OperationGuard::run('link:'.(int)$link['id'], function () use ($link,$source) {
            global $DB;
            // Re-read after the lock: the caller may have loaded a stale snapshot.
            foreach ($DB->request(['FROM'=>'glpi_plugin_demandas_links','WHERE'=>['id'=>(int)$link['id']]]) as $current) {
                return $this->synchronizeLocked($current,$source);
            }
            throw new RuntimeException('O vínculo não foi encontrado. Atualize o chamado.');
        });
    }

    private function synchronizeLocked(array $link, string $source): array
    {
        $ticketId = (int) $link['tickets_id'];
        $wpId = (int) $link['openproject_work_package_id'];
        try {
            $isAutomatic = !in_array($source, ['manual', 'creation'], true);
            $client = $isAutomatic
                ? OpenProjectClient::forAutomation()
                : OpenProjectClient::forCurrentUser();
            $workPackage = $client->getWorkPackage($wpId);
            $status = (string) ($workPackage['_links']['status']['title'] ?? 'Não informado');
            $statusHref = (string) ($workPackage['_links']['status']['href'] ?? '');
            $statusId = preg_match('~/statuses/(\d+)$~', $statusHref, $matches) === 1
                ? (int) $matches[1]
                : null;
            $phase = StatusMapper::publicPhase($status, $statusId, (string) $link['public_phase']);

            $statusChanged = (string) ($link['openproject_status'] ?? '') !== $status;
            $details = $client->summarizeWorkPackage($workPackage);
            $cachedDetails = json_decode((string) ($link['work_package_details_json'] ?? ''), true);
            if (is_array($cachedDetails) && !empty($cachedDetails['creation_reason'])) {
                $details['creation_reason'] = (string) $cachedDetails['creation_reason'];
            }
            // Keep the date of the explicit publication read; status sync is not a new build scan.
            $publications = json_decode((string) ($link['release_details_json'] ?? ''), true);
            if (is_array($publications)) $details['sge_publications'] = $publications;
            OperationGuard::transaction(function () use ($link,$ticketId,$wpId,$status,$phase,$statusId,$statusChanged,$details,$source) {
                global $DB;
                $rule = StatusMapper::rule($statusId);
                if ($statusChanged && !empty($rule['auto_followup']) && trim((string)($rule['message']??''))!=='') {
                    OperationGuard::write($DB->insert('glpi_plugin_demandas_followup_jobs',[
                        'links_id'=>(int)$link['id'],'tickets_id'=>$ticketId,
                        'payload_json'=>json_encode(['link'=>$link,'rule'=>$rule,'status'=>$status,'phase'=>$phase],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                        'state'=>'pending',
                    ]));
                }
                TicketDemand::updateSynchronization($ticketId, $status, $phase, null, $details, $wpId);
                $this->record($link, $source, true, $status, $phase, null, $details);
            });
            // Retried even when status is unchanged. Queue and followup use the
            // same primary transaction, so a failed job cannot publish twice.
            $this->publishPending((int)$link['id']);

            return ['status' => $status, 'public_phase' => $phase, 'work_package' => $workPackage];
        } catch (\Throwable $exception) {
            $message = $exception->getMessage() === self::FOLLOWUP_PENDING
                ? self::FOLLOWUP_PENDING
                : 'Não foi possível concluir a sincronização. Confira o acesso à Work Package e a disponibilidade da integração antes de repetir.';
            try { $this->record($link, $source, false, null, null, $message, ['failure_category'=>IndicatorPolicy::failureCategory($exception)]); }
            catch (\Throwable) { /* A failed audit cannot expose SQL or remote payloads to the user. */ }
            throw new RuntimeException($message,0,$exception);
        }
    }

    private function publishPending(int $linkId): void
    {
        global $DB;
        $jobs = iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_followup_jobs','WHERE'=>['links_id'=>$linkId,'state'=>'pending'],'ORDER'=>'id ASC']));
        foreach ($jobs as $job) {
            try {
                OperationGuard::transaction(function () use ($job,$DB) {
                    $payload=json_decode((string)$job['payload_json'],true,512,JSON_THROW_ON_ERROR);
                    $ticket=new \Ticket();
                    if(!$ticket->getFromDB((int)$job['tickets_id']))throw new RuntimeException('Chamado não encontrado.');
                    (new FollowupPublisher())->publish($ticket,$payload['link'],$payload['rule'],$payload['status'],$payload['phase']);
                    OperationGuard::write($DB->update('glpi_plugin_demandas_followup_jobs',['state'=>'sent','date_mod'=>date('Y-m-d H:i:s')],['id'=>(int)$job['id']]));
                });
            } catch (\Throwable $e) {
                throw new RuntimeException(self::FOLLOWUP_PENDING,0,$e);
            }
        }
    }

    private function record(
        array $link,
        string $source,
        bool $success,
        ?string $newStatus,
        ?string $newPhase,
        ?string $message,
        array $details
    ): void {
        if (in_array($source, ['manual', 'creation'], true)) $details += IntegrationActor::snapshot();
        $db = $GLOBALS['DB'];
        OperationGuard::write($db->insert('glpi_plugin_demandas_events', [
            'tickets_id' => (int) $link['tickets_id'],
            'openproject_work_package_id' => (int) $link['openproject_work_package_id'],
            'event_type' => 'work_package_synchronized',
            'source' => $source,
            'previous_status' => $link['openproject_status'] ?? null,
            'new_status' => $newStatus,
            'previous_public_phase' => $link['public_phase'] ?? null,
            'new_public_phase' => $newPhase,
            'is_success' => $success ? 1 : 0,
            'message' => $message,
            'details_json' => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]));
    }
}
