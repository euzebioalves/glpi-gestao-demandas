<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\OperationalHealthService;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_TECHNICAL);
DemandasProfile::checkRight(DemandasProfile::VIEW_OPERATIONAL_HEALTH);
DemandasProfile::checkRight(DemandasProfile::MANAGE_OPERATIONAL_HEALTH);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Glpi\Exception\Http\BadRequestHttpException();
    }
    $service = new OperationalHealthService();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'run') {
        $result = $service->run((int) Session::getLoginUserID());
        Session::addMessageAfterRedirect($result['findings_count'] . ' pendência(s) identificada(s) na verificação local.', true, INFO);
    } else {
        $service->act((int) ($_POST['finding_id'] ?? 0), $action, (int) Session::getLoginUserID());
        Session::addMessageAfterRedirect('Situação da pendência atualizada e registrada na auditoria.', true, INFO);
    }
} catch (\Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
}
Html::redirect('/plugins/demandas/front/operational-health.php');
