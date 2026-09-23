<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageMonitoringService;

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Glpi\Exception\Http\BadRequestHttpException();
}
(new WorkPackageMonitoringService())->markRead((int) Session::getLoginUserID(), (int) ($_POST['notification_id'] ?? 0));
Html::redirect('/plugins/demandas/front/work-package-notifications.php');
