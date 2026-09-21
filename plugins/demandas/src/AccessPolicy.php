<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use Session;

final class AccessPolicy
{
    public static function has(string $right, ?int $userId = null): bool
    {
        $userId ??= (int) Session::getLoginUserID();
        if ($userId <= 0) return false;
        $db = DBConnection::getReadConnection();
        $rows = $db->request([
            'SELECT' => ['decision'],
            'FROM' => 'glpi_plugin_demandas_user_rights',
            'WHERE' => ['users_id' => $userId, 'right_name' => $right],
            'LIMIT' => 1,
        ]);
        foreach ($rows as $row) return (int) $row['decision'] === 1;
        return Profile::has($right);
    }

    public static function check(string $right): void
    {
        if (!self::has($right)) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
}
