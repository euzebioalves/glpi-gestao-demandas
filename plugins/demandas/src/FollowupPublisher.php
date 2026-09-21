<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;

final class FollowupPublisher
{
    public function publish(\Ticket $ticket, array $link, array $rule, string $status, string $phase): void
    {
        $template = trim((string) ($rule['message'] ?? ''));
        if ($template === '' || empty($rule['auto_followup'])) return;

        $replacements = [
            '{{ticket.id}}' => (string) $ticket->getID(),
            '{{ticket.name}}' => (string) ($ticket->fields['name'] ?? ''),
            '{{requester.name}}' => $this->requesterName($ticket->getID()),
            '{{status.previous}}' => (string) ($link['openproject_status'] ?? ''),
            '{{status.current}}' => $status,
            '{{phase.previous}}' => (string) ($link['public_phase'] ?? ''),
            '{{phase.current}}' => $phase,
            '{{work_package.id}}' => (string) ($link['openproject_work_package_id'] ?? ''),
            '{{project.name}}' => (string) ($link['openproject_project_name'] ?? ''),
        ];

        $followup = new \ITILFollowup();
        $isPrivate = !empty($rule['private_followup']) ? 1 : 0;
        // O webhook é stateless. O GLPI 11 consulta esta preferência durante
        // o pós-processamento e o acompanhamento precisa de um autor real.
        $_SESSION['glpiset_followup_tech'] ??= false;
        $id = $followup->add([
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'users_id' => $this->authorId($ticket),
            'content' => strtr($template, $replacements),
            'is_private' => $isPrivate,
        ]);
        if (!$id) throw new RuntimeException('Não foi possível registrar o acompanhamento automático no chamado.');

        // Confirma a audiência persistida. Isso evita que preferências da
        // sessão usada pelo webhook se sobreponham à regra configurada.
        if ($followup->getFromDB((int) $id) && (int) $followup->fields['is_private'] !== $isPrivate) {
            if (!$followup->update(['id' => (int) $id, 'is_private' => $isPrivate])) {
                throw new RuntimeException('O acompanhamento foi criado, mas sua visibilidade não pôde ser aplicada.');
            }
        }
    }

    private function authorId(\Ticket $ticket): int
    {
        $db = \DBConnection::getReadConnection();
        $iterator = $db->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticket->getID(), 'type' => 2],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($iterator as $actor) {
            if ((int) $actor['users_id'] > 0) return (int) $actor['users_id'];
        }

        $recipient = (int) ($ticket->fields['users_id_recipient'] ?? 0);
        if ($recipient > 0) return $recipient;

        $iterator = $db->request([
            'SELECT' => ['id'],
            'FROM' => 'glpi_users',
            'WHERE' => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($iterator as $user) return (int) $user['id'];
        throw new RuntimeException('Nenhum usuário ativo está disponível para assinar o acompanhamento.');
    }

    private function requesterName(int $ticketId): string
    {
        $db = \DBConnection::getReadConnection();
        $iterator = $db->request([
            'SELECT' => ['glpi_users.name', 'glpi_users.firstname', 'glpi_users.realname'],
            'FROM' => 'glpi_tickets_users',
            'LEFT JOIN' => ['glpi_users' => ['FKEY' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']]],
            'WHERE' => ['glpi_tickets_users.tickets_id' => $ticketId, 'glpi_tickets_users.type' => 1],
            'LIMIT' => 1,
        ]);
        foreach ($iterator as $user) {
            $full = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['realname'] ?? ''));
            return $full !== '' ? $full : (string) ($user['name'] ?? 'Solicitante');
        }
        return 'Solicitante';
    }
}
