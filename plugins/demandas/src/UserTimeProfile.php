<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonDBTM;
use CommonGLPI;
use DBConnection;
use Session;
use User;

final class UserTimeProfile extends CommonDBTM
{
    public static $rightname = Profile::MANAGE_TIME_ACCESS;

    public static function getTypeName($nb = 0): string
    {
        return 'Horas e Ponto';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof User || $item->getID() <= 0) return '';
        $self = (int) $item->getID() === (int) Session::getLoginUserID();
        return ($self || AccessPolicy::has(Profile::MANAGE_ATTENDANCE) || AccessPolicy::has(Profile::MANAGE_TIME_ACCESS))
            ? self::createTabEntry(self::getTypeName())
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof User && $item->getID() > 0) self::showForUser((int) $item->getID());
        return true;
    }

    private static function showForUser(int $userId): void
    {
        $self = $userId === (int) Session::getLoginUserID();
        $canSettings = $self || AccessPolicy::has(Profile::MANAGE_ATTENDANCE);
        $canRights = AccessPolicy::has(Profile::MANAGE_TIME_ACCESS);
        if (!$canSettings && !$canRights) throw new \Glpi\Exception\Http\AccessDeniedHttpException();

        $service = new TimeManagementService();
        $settings = $service->settings($userId);
        $configured = [];
        foreach (DBConnection::getReadConnection()->request([
            'FROM' => 'glpi_plugin_demandas_user_rights',
            'WHERE' => ['users_id' => $userId],
        ]) as $row) {
            $configured[(string) $row['right_name']] = (int) $row['decision'];
        }
        $csrf = Session::getNewCSRFToken();
        echo "<div class='m-3'>";
        if ($canSettings) {
            echo "<div class='card mb-4'><div class='card-header'><h3 class='card-title'>Jornada e vínculo com o OpenProject</h3></div><div class='card-body'>";
            echo "<form method='post' action='/plugins/demandas/front/user-time-profile.form.php'><input type='hidden' name='_glpi_csrf_token' value='{$csrf}'><input type='hidden' name='users_id' value='{$userId}'><input type='hidden' name='save_settings' value='1'>";
            echo "<div class='row g-3'><div class='col-md-3'><label class='form-label'>Entrada</label><input class='form-control' type='time' name='work_start' value='" . self::h(substr((string)$settings['work_start'],0,5)) . "' required></div>";
            echo "<div class='col-md-3'><label class='form-label'>Saída</label><input class='form-control' type='time' name='work_end' value='" . self::h(substr((string)$settings['work_end'],0,5)) . "' required></div>";
            echo "<div class='col-md-3'><label class='form-label'>Intervalo de almoço (min)</label><input class='form-control' type='number' min='0' max='360' name='lunch_minutes' value='" . (int)$settings['lunch_minutes'] . "'></div>";
            echo "<div class='col-md-3'><label class='form-label'>Tolerância diária (min)</label><input class='form-control' type='number' min='0' max='60' name='tolerance_minutes' value='" . (int)$settings['tolerance_minutes'] . "'></div>";
            echo "<div class='col-md-2'><label class='form-label'>UF</label><input class='form-control' maxlength='2' name='state_code' value='" . self::h((string)$settings['state_code']) . "'></div>";
            echo "<div class='col-md-4'><label class='form-label'>Município</label><input class='form-control' name='municipality' value='" . self::h((string)$settings['municipality']) . "'></div>";
            echo "<div class='col-md-6'><label class='form-label'>Usuário do OpenProject</label><input class='form-control' name='openproject_user_href' placeholder='/api/v3/users/123' value='" . self::h((string)$settings['openproject_user_href']) . "'><div class='form-hint'>Preenchimento administrativo usado para atribuir corretamente entradas de tempo.</div></div>";
            foreach ([1,2,3,4,5] as $day) echo "<input type='hidden' name='working_days[]' value='{$day}'>";
            echo "</div><button class='btn btn-primary mt-3'>Salvar configuração individual</button></form></div></div>";
        }
        if ($canRights) {
            $rights = [
                Profile::VIEW_TIME_PORTAL, Profile::LOG_OWN_TIME, Profile::LOG_OTHERS_TIME,
                Profile::VIEW_OWN_ATTENDANCE, Profile::VIEW_TEAM_ATTENDANCE,
                Profile::MANAGE_ATTENDANCE, Profile::MANAGE_HOLIDAYS, Profile::MANAGE_TIME_ACCESS,
            ];
            echo "<div class='card'><div class='card-header'><h3 class='card-title'>Exceções individuais de permissão</h3></div><div class='card-body'><p class='text-muted'>Cada opção pode herdar o perfil, permitir ou negar. Uma decisão individual prevalece sobre o perfil ativo.</p>";
            echo "<form method='post' action='/plugins/demandas/front/user-time-profile.form.php'><input type='hidden' name='_glpi_csrf_token' value='{$csrf}'><input type='hidden' name='users_id' value='{$userId}'><input type='hidden' name='save_rights' value='1'><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>Permissão</th><th style='width:220px'>Decisão para este usuário</th></tr></thead><tbody>";
            foreach ($rights as $right) {
                $value = array_key_exists($right, $configured) ? (string)$configured[$right] : '-1';
                echo '<tr><td>' . self::h(Profile::definitions()[$right]) . "</td><td><select class='form-select' name='rights[" . self::h($right) . "]'>";
                foreach (['-1'=>'Herdar do perfil','1'=>'Permitir','0'=>'Negar'] as $option=>$label) echo "<option value='{$option}'" . ($value===$option?' selected':'') . '>' . $label . '</option>';
                echo '</select></td></tr>';
            }
            echo "</tbody></table></div><button class='btn btn-primary'>Salvar permissões individuais</button></form></div></div>";
        }
        echo '</div>';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
