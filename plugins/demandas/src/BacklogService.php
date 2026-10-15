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

    public function load(): array
    {
        BacklogHub::checkAccess();
        $client = OpenProjectClient::forCurrentUser();
        $userId = (int) ($client->getCurrentUser()['id'] ?? 0);
        if ($userId <= 0) throw new \RuntimeException('Identidade pessoal inválida.');
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
        foreach ($client->getBacklogWorkPackages($userId, array_values(array_intersect($selected, $known))) as $wp) {
            $responsible = (string) ($wp['_links']['responsible']['href'] ?? $wp['_links']['accountable']['href'] ?? '');
            if (!preg_match('~/users/' . $userId . '$~', $responsible)) continue;
            $statusId = (int) basename((string) ($wp['_links']['status']['href'] ?? ''));
            if (!in_array($statusId, $selected, true)) continue;
            $row = $client->summarizeWorkPackage($wp);
            $row['status_id'] = $statusId;
            $row['description'] = (string) ($wp['description']['raw'] ?? '');
            $row['start_date'] = (string) ($wp['startDate'] ?? '');
            $row['due_date'] = (string) ($wp['dueDate'] ?? '');
            $rows[] = $row;
        }
        $timeAvailable = true;
        try { $minutes = $client->getBacklogMinutes($userId, array_column($rows, 'id')); }
        catch (\Throwable) { $minutes = []; $timeAvailable = false; }
        foreach ($rows as &$row) $row['minutes'] = $minutes[$row['id']] ?? null;
        unset($row);
        return compact('statuses', 'selected', 'rows', 'timeAvailable');
    }
}
