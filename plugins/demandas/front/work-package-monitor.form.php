<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_TECHNICAL);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Glpi\Exception\Http\BadRequestHttpException();
    }
    $count = (new WorkPackageMonitoringService())->refreshForUser((int) Session::getLoginUserID());
    Session::addMessageAfterRedirect($count . ' Work Package(s) aberta(s) encontrada(s). Os alertas foram atualizados.', true, INFO);
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
}
Html::redirect('/plugins/demandas/front/work-package-monitor.php');
