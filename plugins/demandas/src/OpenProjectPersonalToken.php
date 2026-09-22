<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;
use Preference;
use Session;

final class OpenProjectPersonalToken extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'OpenProject';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Preference || (int) Session::getLoginUserID() <= 0) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), icon: 'ti ti-key');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Preference) {
            return false;
        }

        $userId = (int) Session::getLoginUserID();
        if ($userId <= 0) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        $configured = Config::hasPersonalToken($userId);
        $csrf = htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES);
        $placeholder = $configured
            ? 'Token já configurado — informe outro valor para substituí-lo'
            : 'Informe o token de acesso do OpenProject';

        echo "<div class='m-3'><div class='card'><div class='card-header'><h3 class='card-title'>Meu acesso ao OpenProject</h3></div><div class='card-body'>";
        echo "<p class='text-muted'>Este token é pessoal e será usado nas suas criações, sincronizações manuais e lançamentos de tempo. O token automático do plugin não é usado nessas ações.</p>";
        echo "<form method='post' action='/plugins/demandas/front/personal-token.form.php' class='row g-3'><input type='hidden' name='_glpi_csrf_token' value='{$csrf}'>";
        echo "<div class='col-md-8'><label class='form-label' for='personal_openproject_api_token'>Meu token da API</label><input class='form-control' id='personal_openproject_api_token' name='personal_openproject_api_token' type='password' autocomplete='new-password' placeholder='" . htmlspecialchars($placeholder, ENT_QUOTES) . "'><div class='form-hint'>O valor não é exibido novamente. Deixe em branco para manter o token atual.</div></div>";
        echo "<div class='col-md-4 d-flex align-items-end gap-2'><button class='btn btn-primary' name='save_personal_token' value='1'>Salvar meu token</button><button class='btn btn-outline-primary' name='test_personal_token' value='1'>Salvar e testar</button></div></form>";
        echo '</div></div></div>';

        return true;
    }
}
