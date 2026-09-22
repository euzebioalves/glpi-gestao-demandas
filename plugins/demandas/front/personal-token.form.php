<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\OpenProjectClient;

Session::checkLoginUser();

try {
    if (!isset($_POST['save_personal_token']) && !isset($_POST['test_personal_token'])) {
        throw new Glpi\Exception\Http\BadRequestHttpException();
    }

    $userId = (int) Session::getLoginUserID();
    DemandasConfig::savePersonalToken($userId, (string) ($_POST['personal_openproject_api_token'] ?? ''));
    if (isset($_POST['test_personal_token'])) {
        OpenProjectClient::forCurrentUser()->testConnection();
        Session::addMessageAfterRedirect('Seu token foi salvo e a conexão com o OpenProject foi validada.', true, INFO);
    } else {
        Session::addMessageAfterRedirect('Seu token de acesso ao OpenProject foi salvo.', true, INFO);
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect('Não foi possível salvar o token pessoal do OpenProject.', true, ERROR);
}

Html::redirect('/front/preference.php?forcetab=' . rawurlencode('GlpiPlugin\\Demandas\\OpenProjectPersonalToken$1'));
