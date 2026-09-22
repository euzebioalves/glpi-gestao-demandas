<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;

final class SynchronizationService
{
    public function synchronizeByWorkPackage(int $workPackageId, string $source = 'webhook'): array
    {
        $link = TicketDemand::findByWorkPackage($workPackageId);
        if ($link === null) {
            throw new RuntimeException('A Work Package recebida não possui vínculo no GLPI.');
        }

        return $this->synchronizeLink($link, $source);
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
                    $exception->getMessage()
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
            $followupError = null;
            if ($statusChanged) {
                $ticket = new \Ticket();
                if ($ticket->getFromDB($ticketId)) {
                    try {
                        (new FollowupPublisher())->publish($ticket, $link, StatusMapper::rule($statusId), $status, $phase);
                    } catch (\Throwable $exception) {
                        $followupError = 'Fase sincronizada, mas o acompanhamento não foi criado: ' . $exception->getMessage();
                    }
                }
            }
            $details = $client->summarizeWorkPackage($workPackage);
            $cachedDetails = json_decode((string) ($link['work_package_details_json'] ?? ''), true);
            if (is_array($cachedDetails) && !empty($cachedDetails['creation_reason'])) {
                $details['creation_reason'] = (string) $cachedDetails['creation_reason'];
            }
            TicketDemand::updateSynchronization($ticketId, $status, $phase, null, $details, $wpId);
            $this->record($link, $source, true, $status, $phase, $followupError, $details);

            return ['status' => $status, 'public_phase' => $phase, 'work_package' => $workPackage];
        } catch (\Throwable $exception) {
            $this->record($link, $source, false, null, null, $exception->getMessage(), []);
            throw $exception;
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
        $db = \DBConnection::getReadConnection();
        $db->insert('glpi_plugin_demandas_events', [
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
        ]);
    }
}
