<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Profile_User;
use Session;
use User;

/** Shared authorization for time/attendance. Does not grant or migrate rights. */
final class TimeAccess
{
    public static function exceptionRights(): array
    {
        // Preserve all eight exceptions already offered by the user's tab.
        return [Profile::VIEW_TIME_PORTAL, Profile::LOG_OWN_TIME, Profile::LOG_OTHERS_TIME,
            Profile::VIEW_OWN_ATTENDANCE, Profile::VIEW_TEAM_ATTENDANCE,
            Profile::MANAGE_ATTENDANCE, Profile::MANAGE_HOLIDAYS, Profile::MANAGE_TIME_ACCESS];
    }

    public static function targetInScope(int $userId): bool
    {
        if ($userId <= 0 || (int) Session::getLoginUserID() <= 0) return false;
        $user = new User();
        if (!$user->getFromDB($userId) || !empty($user->fields['is_deleted'])) return false;
        if ($userId === (int) Session::getLoginUserID()) return true;
        // A module permission delegates the action; active entities still bound the target.
        return array_intersect(array_map('intval', Session::getActiveEntities()),
            array_map('intval', Profile_User::getUserEntities($userId))) !== [];
    }

    public static function checkTarget(int $userId): void
    {
        if (!self::targetInScope($userId)) throw new AccessDeniedHttpException();
    }

    public static function canSettings(int $userId): bool
    {
        return AccessPolicy::has(Profile::VIEW_TIME_PORTAL)
            && AccessPolicy::has($userId === (int) Session::getLoginUserID() ? Profile::VIEW_OWN_ATTENDANCE : Profile::MANAGE_ATTENDANCE)
            && self::targetInScope($userId);
    }

    public static function checkSettings(int $userId): void
    {
        if (!self::canSettings($userId)) throw new AccessDeniedHttpException();
    }

    public static function checkAttendance(int $userId, bool $write = false): void
    {
        AccessPolicy::check(Profile::VIEW_TIME_PORTAL);
        if ($write) {
            self::checkSettings($userId);
            return;
        }
        AccessPolicy::check(Profile::VIEW_OWN_ATTENDANCE);
        if ($userId !== (int) Session::getLoginUserID()) AccessPolicy::check(Profile::VIEW_TEAM_ATTENDANCE);
        self::checkTarget($userId);
    }

    public static function canManageRights(int $userId): bool
    {
        return AccessPolicy::has(Profile::VIEW_TIME_PORTAL)
            && AccessPolicy::has(Profile::MANAGE_TIME_ACCESS) && self::targetInScope($userId);
    }

    public static function checkManageRights(int $userId): void
    {
        if (!self::canManageRights($userId)) throw new AccessDeniedHttpException();
    }

    public static function checkTimeEntry(int $userId): void
    {
        AccessPolicy::check(Profile::VIEW_TIME_PORTAL);
        AccessPolicy::check($userId === (int) Session::getLoginUserID() ? Profile::LOG_OWN_TIME : Profile::LOG_OTHERS_TIME);
        self::checkTarget($userId);
    }

    /** Call only after the page's feature permission has been checked. */
    public static function selectableUsers(): array
    {
        $users = [];
        foreach (DBConnection::getReadConnection()->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users',
            'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => ['realname ASC', 'firstname ASC'],
        ]) as $user) {
            if (self::targetInScope((int) $user['id'])) $users[] = $user;
        }
        return $users;
    }

    public static function saveExceptions(int $userId, array $decisions): void
    {
        self::checkManageRights($userId);
        // Validate the entire batch before writing any decision.
        foreach ($decisions as $right => $decision) {
            if (!in_array($right, self::exceptionRights(), true)
                || !in_array($decision, [-1, 0, 1, '-1', '0', '1'], true)) {
                throw new \RuntimeException('Permissão ou decisão individual inválida. Use Herdar, Permitir ou Negar somente para direitos de horas e ponto.');
            }
        }
        global $DB;
        $DB->beginTransaction();
        try {
            foreach ($decisions as $right => $decision) {
                $where = ['users_id' => $userId, 'right_name' => $right];
                $existing = $DB->request(['FROM'=>'glpi_plugin_demandas_user_rights', 'WHERE'=>$where, 'LIMIT'=>1])->current();
                $before = $existing ? (int) $existing['decision'] : -1;
                $after = (int) $decision;
                if ($before === $after) continue;
                if ($after === -1) {
                    $DB->delete('glpi_plugin_demandas_user_rights', $where);
                } else {
                    $DB->updateOrInsert('glpi_plugin_demandas_user_rights', ['decision'=>$after, 'date_mod'=>date('Y-m-d H:i:s')], $where);
                }
                $DB->insert('glpi_plugin_demandas_time_audit', [
                    'action'=>'access.update', 'actor_users_id'=>(int) Session::getLoginUserID(), 'target_users_id'=>$userId,
                    'details_json'=>json_encode(['right'=>$right, 'before'=>$before, 'after'=>$after], JSON_THROW_ON_ERROR),
                    'date_creation'=>date('Y-m-d H:i:s'),
                ]);
            }
            $DB->commit();
        } catch (\Throwable $exception) {
            $DB->rollBack();
            throw $exception;
        }
    }
}
