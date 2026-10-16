<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Presentation of audit identities; historical records are never rewritten. */
final class IntegrationActor
{
    public static function name(int $id, string $fallback = ''): string
    {
        static $names = [];
        if ($id <= 0) return $fallback ?: 'Não registrado';
        if (!array_key_exists($id, $names)) {
            $row = \DBConnection::getReadConnection()->request([
                'SELECT' => ['name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $id],
            ])->current();
            $names[$id] = $row ? (trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? '')) ?: (string) $row['name']) : '';
        }
        return $names[$id] ?: ($fallback ?: 'Usuário indisponível (#' . $id . ')');
    }

    public static function snapshot(): array
    {
        $id = (int) \Session::getLoginUserID();
        return ['actor_user_id' => $id, 'actor_name' => self::name($id)];
    }

    public static function eventName(array $event): string
    {
        $details = json_decode((string) ($event['details_json'] ?? ''), true) ?: [];
        $id = (int) ($details['actor_user_id'] ?? $details['reconciliation_user_id'] ?? 0);
        if (!$id && ($event['source'] ?? '') === 'legacy_reconciliation'
            && preg_match('/por usuário #(\d+)/u', (string) ($event['message'] ?? ''), $match)) $id = (int) $match[1];
        if ($id) return self::name($id, (string) ($details['actor_name'] ?? ''));
        return ($event['source'] ?? '') === 'webhook' ? 'Automação (webhook)' : 'Não registrado';
    }

    public static function message(array $event): string
    {
        $message = (string) ($event['message'] ?? '');
        if (($event['source'] ?? '') === 'legacy_reconciliation') {
            $message = preg_replace_callback('/por usuário #\d+/u', static fn() => 'por ' . self::eventName($event), $message);
        }
        return $message;
    }
}
