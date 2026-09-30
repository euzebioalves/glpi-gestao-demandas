<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DomainException;
use Session;
use Ticket;

/** Imports local links after human confirmation; never creates or updates a WP. */
final class LegacyReconciliationService
{
    public static function checkAccess(): void
    {
        Session::checkLoginUser();
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        Profile::checkRight(Profile::CREATE_WORK_PACKAGE);
    }

    /** Only complete URLs belonging to the configured OpenProject are evidence. */
    public static function parseIds(string $value, string $baseUrl): array
    {
        $value = trim(html_entity_decode(rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $base = parse_url(rtrim($baseUrl, '/'));
        if (!is_array($base) || empty($base['host'])) return [];
        preg_match_all('~https?://[^\s<>"\x27]+~iu', $value, $urls);
        $ids = [];
        foreach ($urls[0] as $url) {
            $parts = parse_url(rtrim($url, '.,;)'));
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) continue;
            $port = static fn(array $p): int => (int) ($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
            if (strcasecmp((string) ($parts['host'] ?? ''), $base['host']) !== 0 || $port($parts) !== $port($base)) continue;
            $prefix = preg_quote(rtrim((string) ($base['path'] ?? ''), '/'), '~');
            if (preg_match('~^' . $prefix . '/(?:projects/[^/]+/)?work_packages/([1-9][0-9]*)(?:/|$)~', (string) ($parts['path'] ?? ''), $match)) {
                $ids[] = (int) $match[1];
            }
        }
        return array_values(array_unique($ids));
    }

    private function references(): array
    {
        $map = [];
        $baseUrl = (string) Config::get('openproject_external_url', '');
        $db = \DBConnection::getReadConnection();
        foreach (FieldsClassificationProvider::workPackageLinkFields() as $field) {
            foreach ($db->request([
                'SELECT' => [$field['ticket_key'], $field['value_field']],
                'FROM' => $field['table'],
                'WHERE' => [$field['itemtype_field'] => Ticket::class],
            ]) as $row) {
                $ticketId = (int) $row[$field['ticket_key']];
                $value = trim((string) $row[$field['value_field']]);
                if ($ticketId <= 0 || $value === '') continue;
                $map[$ticketId] ??= [];
                foreach (self::parseIds($value, $baseUrl) as $id) {
                    $map[$ticketId][$id] = $id;
                }
            }
        }
        return $map;
    }

    private function ticket(int $id): ?Ticket
    {
        $ticket = new Ticket();
        $entities = array_map('intval', Session::getActiveEntities());
        if ($id <= 0 || !$ticket->getFromDB($id) || (int) ($ticket->fields['is_deleted'] ?? 0) !== 0
            || !in_array((int) $ticket->fields['entities_id'], $entities, true) || !$ticket->can($id, READ)) return null;
        return $ticket;
    }

    public function preview(bool $all = false, int $onlyTicket = 0): array
    {
        self::checkAccess();
        $references = $this->references();
        $owners = [];
        foreach ($references as $ticketId => $ids) {
            foreach ($ids as $id) $owners[$id][$ticketId] = true;
        }
        $rows = [];
        foreach ($references as $ticketId => $ids) {
            if ($onlyTicket > 0 && $ticketId !== $onlyTicket) continue;
            $ticket = $this->ticket($ticketId);
            if ($ticket === null || (!$all && in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true))) continue;
            foreach ($ids ?: [0] as $wpId) {
                $link = $wpId > 0 ? TicketDemand::findByWorkPackage($wpId) : null;
                $state = 'ready';
                if ($wpId <= 0) $state = 'invalid';
                elseif (count($owners[$wpId] ?? []) > 1 || ($link !== null && (int) $link['tickets_id'] !== $ticketId)) $state = 'conflict';
                elseif ($link !== null) $state = 'linked';
                elseif (!$ticket->can($ticketId, UPDATE)) $state = 'readonly';
                $rows[] = ['ticket_id' => $ticketId, 'ticket_name' => (string) $ticket->fields['name'], 'wp_id' => $wpId, 'state' => $state];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $b['ticket_id'] <=> $a['ticket_id'] ?: $a['wp_id'] <=> $b['wp_id']);
        return $rows;
    }

    public function reconcile(int $ticketId, int $wpId): string
    {
        self::checkAccess();
        $ticket = $this->ticket($ticketId);
        if ($ticket === null || !$ticket->can($ticketId, UPDATE)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $candidate = null;
        foreach ($this->preview(true, $ticketId) as $row) {
            if ($row['wp_id'] === $wpId) $candidate = $row;
        }
        if ($candidate === null || !in_array($candidate['state'], ['ready', 'linked'], true)) {
            throw new DomainException('Referência ausente, inválida ou conflitante. Revise Atividade DevOps antes de conciliar.');
        }
        if ($candidate['state'] === 'linked') return 'already_linked';

        try {
            // Fixed API endpoint, using the personal token; the field URL is never fetched.
            $wp = OpenProjectClient::forCurrentUser()->getWorkPackage($wpId);
        } catch (\Throwable) {
            throw new DomainException('Não foi possível validar a WP. Confira o token pessoal, o acesso e a disponibilidade do OpenProject.');
        }
        if ((int) ($wp['id'] ?? 0) !== $wpId) throw new DomainException('O OpenProject não confirmou a WP solicitada.');
        $resourceId = static function (string $name, string $collection) use ($wp): int {
            return preg_match('~/api/v3/' . $collection . '/([1-9][0-9]*)$~', (string) ($wp['_links'][$name]['href'] ?? ''), $m) ? (int) $m[1] : 0;
        };
        $projectId = $resourceId('project', 'projects');
        $typeId = $resourceId('type', 'types');
        if ($projectId <= 0 || $typeId <= 0) throw new DomainException('A WP não informou projeto e tipo válidos.');
        $details = ['id' => $wpId, 'subject' => (string) ($wp['subject'] ?? ''), 'created_at' => (string) ($wp['createdAt'] ?? ''), 'updated_at' => (string) ($wp['updatedAt'] ?? ''), 'customer' => ''];
        foreach (['project', 'type', 'status', 'priority', 'assignee', 'responsible'] as $key) {
            $details[$key] = (string) ($wp['_links'][$key]['title'] ?? '');
        }

        global $DB;
        // Recheck source and permissions after network I/O, before any local write.
        $fresh = $this->ticket($ticketId);
        if ($fresh === null || !$fresh->can($ticketId, UPDATE)) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        $valid = false;
        foreach ($this->preview(true, $ticketId) as $row) {
            if ($row['wp_id'] === $wpId && in_array($row['state'], ['ready', 'linked'], true)) $valid = true;
        }
        if (!$valid) throw new DomainException('A referência mudou durante a validação. Atualize a prévia.');
        $DB->beginTransaction();
        try {
            // Do not use saveLink(): its upsert can move an existing WP to another ticket.
            $existing = iterator_to_array($DB->request(['FROM' => 'glpi_plugin_demandas_links', 'WHERE' => ['openproject_work_package_id' => $wpId]]));
            if ($existing !== []) {
                if ((int) reset($existing)['tickets_id'] === $ticketId) {
                    $DB->rollBack();
                    return 'already_linked';
                }
                throw new DomainException('A WP já possui outro vínculo. Nenhum vínculo foi alterado.');
            }
            $now = date('Y-m-d H:i:s');
            $ok = $DB->insert('glpi_plugin_demandas_links', [
                'tickets_id' => $ticketId, 'openproject_work_package_id' => $wpId,
                'openproject_project_id' => $projectId, 'openproject_project_name' => mb_substr($details['project'], 0, 255),
                'openproject_type_id' => $typeId, 'openproject_type_name' => mb_substr($details['type'], 0, 255),
                'openproject_status' => mb_substr($details['status'], 0, 120),
                // Importing metadata does not publish a new customer-facing phase or followup.
                'public_phase' => '', 'last_synced_at' => $now,
                'work_package_details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if (!$ok) throw new DomainException('Falha ao registrar vínculo.');
            $ok = $DB->insert('glpi_plugin_demandas_events', [
                'tickets_id' => $ticketId, 'openproject_work_package_id' => $wpId,
                'event_type' => 'legacy_link_reconciled', 'source' => 'legacy_reconciliation', 'is_success' => 1,
                'new_status' => mb_substr($details['status'], 0, 120),
                'message' => 'Vínculo de Atividade DevOps conciliado por usuário #' . (int) Session::getLoginUserID() . '. Nenhuma WP foi criada.',
                'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if (!$ok) throw new DomainException('Falha ao registrar auditoria.');
            $DB->commit();
            return 'linked';
        } catch (\Throwable) {
            $DB->rollBack();
            throw new DomainException('Não foi possível gravar a conciliação. Atualize a prévia e tente novamente; vínculos existentes foram preservados.');
        }
    }
}
