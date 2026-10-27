<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\TimeManagementService;
use GlpiPlugin\Demandas\TimeAccess;

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Glpi\Exception\Http\BadRequestHttpException();
$userId = (int) ($_POST['users_id'] ?? 0);
if (isset($_POST['save_settings']) === isset($_POST['save_rights'])) throw new Glpi\Exception\Http\BadRequestHttpException();
if (isset($_POST['save_settings'])) TimeAccess::checkSettings($userId);
else TimeAccess::checkManageRights($userId);

try {
    if (isset($_POST['save_settings'])) {
        $input = $_POST;
        $input['openproject_user_name'] = '';
        (new TimeManagementService())->saveSettings($userId, $input);
        Session::addMessageAfterRedirect('Configuração individual atualizada.', true, INFO);
    } elseif (isset($_POST['save_rights'])) {
        TimeAccess::saveExceptions($userId, (array) ($_POST['rights'] ?? []));
        Session::addMessageAfterRedirect('Permissões individuais atualizadas.', true, INFO);
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect(\GlpiPlugin\Demandas\IntegrationSecurity::html($exception), true, ERROR);
}
Html::redirect('/front/user.form.php?id=' . $userId . '&forcetab=GlpiPlugin\\Demandas\\UserTimeProfile$1');
