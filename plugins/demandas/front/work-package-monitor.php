<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;

Session::checkLoginUser();
$userId = (int) Session::getLoginUserID();
$service = new WorkPackageMonitoringService();
$rows = $service->userRows($userId);

Html::header('Minhas Work Packages', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Minhas Work Packages abertas</h1><p class='text-muted mb-0'>Consulte User Stories, Épicos e Bugs abertos pelos quais você é responsável no OpenProject.</p></div><form method='post' action='/plugins/demandas/front/work-package-monitor.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><button class='btn btn-primary'><i class='ti ti-refresh me-1'></i>Consultar minhas WPs</button></form></div>";
if (!DemandasConfig::hasPersonalToken($userId)) {
    echo "<div class='alert alert-warning'>Seu token pessoal do OpenProject ainda não foi configurado. <a class='alert-link' href='/front/preference.php?forcetab=" . rawurlencode('GlpiPlugin\\Demandas\\OpenProjectPersonalToken$1') . "'>Configurar token</a>.</div>";
}
if ($rows !== []) {
    WorkPackageMonitoringView::renderStatusCards($rows);
    echo "<p class='text-muted small'>Apenas WPs abertas da última consulta são exibidas. Os vínculos incluem o campo Atividade DevOps, acompanhamentos e vínculos já registrados pelo plugin.</p>";
}
WorkPackageMonitoringView::renderRows($rows);
echo '</div>';
Html::footer();
