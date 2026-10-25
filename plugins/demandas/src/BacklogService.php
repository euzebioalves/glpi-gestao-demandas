<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class BacklogService
{
    private const TABLE = 'glpi_plugin_demandas_backlog_preferences';

    public function preferences(): ?array
    {
        BacklogHub::checkAccess();
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => (int) \Session::getLoginUserID()]]) as $row) {
            return json_decode($row['status_ids_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        return null;
    }

    public function save(array $ids): void
    {
        BacklogHub::checkAccess();
        Profile::checkRight(Profile::CONFIGURE_BACKLOG);
        if (count($ids) > 500) throw new \InvalidArgumentException('Seleção de colunas inválida.');
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) throw new \InvalidArgumentException('Seleção de colunas inválida.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        // Keep previously selected IDs even when their status is no longer in the catalog.
        $allowed = array_merge(array_column(OpenProjectClient::forCurrentUser()->getStatuses(), 'id'), $this->preferences() ?? []);
        if (array_diff($ids, $allowed) !== []) throw new \InvalidArgumentException('Seleção de colunas inválida.');
        global $DB;
        $where = ['users_id' => (int) \Session::getLoginUserID()];
        $data = ['status_ids_json' => json_encode($ids, JSON_THROW_ON_ERROR), 'date_mod' => date('Y-m-d H:i:s')];
        // The unique user key prevents duplicate preferences across concurrent tabs.
        if ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where])->count() > 0) {
            $ok = $DB->update(self::TABLE, $data, $where);
        } else {
            $ok = $DB->insert(self::TABLE, $data + $where);
        }
        if (!$ok) throw new \RuntimeException('Não foi possível salvar as colunas.');
    }

    public function load(int $responsibleId = 0): array
    {
        BacklogHub::checkAccess();
        $client = OpenProjectClient::forCurrentUser();
        $userId = (int) ($client->getCurrentUser()['id'] ?? 0);
        if ($userId <= 0) throw new \RuntimeException('Identidade pessoal inválida.');
        if ($responsibleId > 0 && $responsibleId !== $userId) Profile::checkRight(Profile::VIEW_COLLEAGUE_BACKLOG);
        $ownerId = $responsibleId > 0 ? $responsibleId : $userId;
        $statuses = $client->getStatuses();
        $priority = ['novo', 'em especificação', 'em dúvida'];
        usort($statuses, static function ($a, $b) use ($priority): int {
            $rank = static function ($status) use ($priority): int {
                $index = array_search(mb_strtolower(trim((string) ($status['name'] ?? ''))), $priority, true);
                return $index === false ? 3 : $index;
            };
            return ($rank($a) <=> $rank($b)) ?: ((int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));
        });
        $selected = $this->preferences();
        if ($selected === null) {
            $selected = array_map('intval', array_column(array_filter($statuses, static fn($s) => empty($s['isClosed'])), 'id'));
        }
        $known = array_map('intval', array_column($statuses, 'id'));
        $rows = [];
        foreach ($client->getBacklogWorkPackages($ownerId, array_values(array_intersect($selected, $known))) as $wp) {
            $responsible = (string) ($wp['_links']['responsible']['href'] ?? $wp['_links']['accountable']['href'] ?? '');
            if (!preg_match('~/users/' . $ownerId . '$~', $responsible)) continue;
            $statusId = (int) basename((string) ($wp['_links']['status']['href'] ?? ''));
            if (!in_array($statusId, $selected, true)) continue;
            $row = $client->summarizeWorkPackage($wp, true);
            $row['status_id'] = $statusId;
            $row['start_date'] = (string) ($wp['startDate'] ?? '');
            $row['due_date'] = (string) ($wp['dueDate'] ?? '');
            $rows[] = $row;
        }
        $tickets = $this->tickets(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['minutes'] = null;
            $row['ticket_ids'] = $tickets[$row['id']] ?? [];
        }
        unset($row);
        return compact('statuses', 'selected', 'rows', 'ownerId', 'userId');
    }

    /** Only confirmed local links; each ticket retains native and entity authorization. */
    public function tickets(array $wpIds): array
    {
        BacklogHub::checkAccess();
        if (!$wpIds) return [];
        global $DB;
        $map = []; $allowed = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_demandas_links', 'WHERE' => ['openproject_work_package_id' => $wpIds]]) as $link) {
            $id = (int) $link['tickets_id'];
            if (!array_key_exists($id, $allowed)) {
                $ticket = new \Ticket();
                $allowed[$id] = $ticket->getFromDB($id) && empty($ticket->fields['is_deleted'])
                    && in_array((int) $ticket->fields['entities_id'], array_map('intval', \Session::getActiveEntities()), true)
                    && $ticket->can($id, READ);
            }
            if ($allowed[$id]) $map[(int) $link['openproject_work_package_id']][] = $id;
        }
        return $map;
    }

    public function times(array $ids): array
    {
        BacklogHub::checkAccess();
        if (count($ids) > 25) throw new \InvalidArgumentException('Consulte até 25 WPs por vez.');
        foreach ($ids as $id) if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) throw new \InvalidArgumentException('WP inválida.');
        $client = OpenProjectClient::forCurrentUser();
        $user = (int) ($client->getCurrentUser()['id'] ?? 0);
        if (!$user) throw new \RuntimeException('Identidade pessoal inválida.');
        return $client->getBacklogMinutes($user, array_values(array_unique(array_map('intval', $ids))));
    }
}
