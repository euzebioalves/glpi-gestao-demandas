<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonDBTM;
use CommonGLPI;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

final class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public const VIEW_PUBLIC = 'demandas_view_public';
    public const VIEW_TECHNICAL = 'demandas_view_technical';
    public const VIEW_HISTORY = 'demandas_view_history';
    public const CREATE_WORK_PACKAGE = 'demandas_create_workpackage';
    public const SYNC_WORK_PACKAGE = 'demandas_sync_workpackage';
    public const MANAGE_CONFIG = 'demandas_manage_config';
    public const VIEW_DASHBOARD = 'demandas_view_dashboard';
    public const EXPORT_DASHBOARD = 'demandas_export_dashboard';
    public const VIEW_TICKET_LOG = 'demandas_view_ticket_log';
    public const VIEW_TIME_PORTAL = 'demandas_view_time_portal';
    public const LOG_OWN_TIME = 'demandas_log_own_time';
    public const LOG_OTHERS_TIME = 'demandas_log_others_time';
    public const VIEW_OWN_ATTENDANCE = 'demandas_view_own_attendance';
    public const VIEW_TEAM_ATTENDANCE = 'demandas_view_team_attendance';
    public const MANAGE_ATTENDANCE = 'demandas_manage_attendance';
    public const MANAGE_HOLIDAYS = 'demandas_manage_holidays';
    public const MANAGE_TIME_ACCESS = 'demandas_manage_time_access';
    public const PREPARE_AI_CONTEXT = 'demandas_prepare_ai_context';

    public static function getTypeName($nb = 0): string
    {
        return 'Gestão de Demandas';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        return $item instanceof GlpiProfile && $item->getID() > 0
            ? self::createTabEntry(self::getTypeName())
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof GlpiProfile && $item->getID() > 0) {
            self::showForProfile($item->getID());
        }
        return true;
    }

    public static function definitions(): array
    {
        return [
            self::VIEW_PUBLIC => 'Visualizar a evolução pública da demanda',
            self::VIEW_TECHNICAL => 'Visualizar dados técnicos do OpenProject',
            self::VIEW_HISTORY => 'Visualizar o histórico da integração',
            self::CREATE_WORK_PACKAGE => 'Criar e vincular Work Packages',
            self::SYNC_WORK_PACKAGE => 'Executar sincronização manual',
            self::MANAGE_CONFIG => 'Administrar as configurações do plugin',
            self::VIEW_DASHBOARD => 'Acessar a visão gerencial',
            self::EXPORT_DASHBOARD => 'Exportar a visão gerencial em PDF e Excel',
            self::VIEW_TICKET_LOG => 'Visualizar o log organizado do chamado',
            self::VIEW_TIME_PORTAL => 'Acessar o portal de horas e ponto',
            self::LOG_OWN_TIME => 'Lançar as próprias horas nas Work Packages',
            self::LOG_OTHERS_TIME => 'Lançar horas nas Work Packages para outros usuários',
            self::VIEW_OWN_ATTENDANCE => 'Visualizar e registrar o próprio ponto',
            self::VIEW_TEAM_ATTENDANCE => 'Visualizar o ponto e ausências de outros usuários',
            self::MANAGE_ATTENDANCE => 'Administrar jornadas, marcações e ausências',
            self::MANAGE_HOLIDAYS => 'Administrar feriados e compensações',
            self::MANAGE_TIME_ACCESS => 'Administrar exceções individuais de acesso',
            self::PREPARE_AI_CONTEXT => 'Preparar contexto de chamado e WP para IA externa',
        ];
    }

    public static function getAllRights($all = false): array
    {
        $rights = [];
        foreach (self::definitions() as $field => $label) {
            $rights[] = [
                'itemtype' => TicketDemand::class,
                'label' => $label,
                'field' => $field,
            ];
        }
        return $rights;
    }

    public static function has(string $right): bool
    {
        return (bool) Session::haveRight($right, READ);
    }

    public static function checkRight(string $right): void
    {
        Session::checkRight($right, READ);
    }

    public static function setRight(int $profileId, string $right, bool $enabled): void
    {
        if (!array_key_exists($right, self::definitions())) {
            throw new \InvalidArgumentException('Direito desconhecido do plugin.');
        }

        $profileRight = new ProfileRight();
        $found = $profileRight->find([
            'profiles_id' => $profileId,
            'name' => $right,
        ]);
        $value = $enabled ? READ : 0;
        if ($found !== []) {
            $id = (int) array_key_first($found);
            $profileRight->update(['id' => $id, 'rights' => $value]);
        } else {
            $profileRight->add([
                'profiles_id' => $profileId,
                'name' => $right,
                'rights' => $value,
            ]);
        }

        if ($right === self::VIEW_PUBLIC && $enabled) {
            self::ensurePublicFollowupRight($profileId);
        }
    }

    /**
     * GLPI filters public followups using its native `followup` right before
     * plugin rights are considered. Preserve every existing native bit and
     * only add the minimum permission required to see public followups.
     */
    public static function ensurePublicFollowupRight(int $profileId): void
    {
        $profileRight = new ProfileRight();
        $found = $profileRight->find([
            'profiles_id' => $profileId,
            'name' => \ITILFollowup::$rightname,
        ]);

        if ($found !== []) {
            $id = (int) array_key_first($found);
            $current = (int) ($found[$id]['rights'] ?? 0);
            $required = $current | \ITILFollowup::SEEPUBLIC;
            if ($required !== $current) {
                $profileRight->update(['id' => $id, 'rights' => $required]);
            }
            return;
        }

        $profileRight->add([
            'profiles_id' => $profileId,
            'name' => \ITILFollowup::$rightname,
            'rights' => \ITILFollowup::SEEPUBLIC,
        ]);
    }

    public static function initializeProfile(int $profileId, array $enabledRights): void
    {
        $profileRight = new ProfileRight();
        foreach (self::definitions() as $right => $label) {
            $found = $profileRight->find([
                'profiles_id' => $profileId,
                'name' => $right,
            ]);
            if ($found === []) {
                $profileRight->add([
                    'profiles_id' => $profileId,
                    'name' => $right,
                    'rights' => in_array($right, $enabledRights, true) ? READ : 0,
                ]);
            }
        }

        if (in_array(self::VIEW_PUBLIC, $enabledRights, true)) {
            self::ensurePublicFollowupRight($profileId);
        }
    }

    private static function valuesForProfile(int $profileId): array
    {
        $values = array_fill_keys(array_keys(self::definitions()), 0);
        $profileRight = new ProfileRight();
        foreach ($profileRight->find(['profiles_id' => $profileId]) as $row) {
            $name = (string) ($row['name'] ?? '');
            if (array_key_exists($name, $values)) {
                $values[$name] = (int) ($row['rights'] ?? 0);
            }
        }
        return $values;
    }

    private static function showForProfile(int $profileId): void
    {
        $values = self::valuesForProfile($profileId);
        $canEdit = Session::haveRight('profile', UPDATE);

        echo "<div class='card m-3'><div class='card-header'><h3 class='card-title'>Permissões — Gestão de Demandas</h3></div><div class='card-body'>";
        echo "<p class='text-muted'>Estas permissões são validadas também no servidor e nos endpoints do plugin. Ao conceder a visualização pública, o plugin garante também a permissão nativa mínima para ler acompanhamentos públicos.</p>";
        echo "<form method='post' action='/plugins/demandas/front/profile.form.php'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
        echo "<input type='hidden' name='profiles_id' value='{$profileId}'>";
        echo "<div class='table-responsive'><table class='table table-sm align-middle'><thead><tr><th>Permissão</th><th class='text-center'>Concedida</th></tr></thead><tbody>";
        foreach (self::definitions() as $right => $label) {
            $checked = (($values[$right] ?? 0) & READ) === READ ? ' checked' : '';
            $disabled = $canEdit ? '' : ' disabled';
            echo '<tr><td>' . htmlspecialchars($label) . "</td><td class='text-center'><input class='form-check-input' type='checkbox' name='rights[]' value='" . htmlspecialchars($right, ENT_QUOTES) . "'{$checked}{$disabled}></td></tr>";
        }
        echo '</tbody></table></div>';
        if ($canEdit) {
            echo "<button class='btn btn-primary' type='submit' name='update' value='1'>Salvar permissões</button>";
        }
        echo '</form></div></div>';
    }
}
