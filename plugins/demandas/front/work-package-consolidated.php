<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_DASHBOARD);
$service = new WorkPackageMonitoringService();
$responsible = trim((string) ($_GET['responsible'] ?? ''));
$rows = $service->consolidatedRows($responsible);

Html::header('Consolidado de Work Packages', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Consolidado de Work Packages</h1><p class='text-muted mb-0'>Visão gerencial formada pelas consultas pessoais executadas pelos usuários.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-monitor.php'><i class='ti ti-user me-1'></i>Minhas WPs</a></div>";
echo "<form class='card card-body mb-4' method='get'><div class='row align-items-end g-3'><div class='col-md-5'><label class='form-label' for='responsible'>Responsável da WP</label><select class='form-select' id='responsible' name='responsible'><option value=''>Todos os responsáveis</option>";
foreach ($service->responsibleOptions() as $name) {
    echo "<option value='" . WorkPackageMonitoringView::escape($name) . "'" . ($name === $responsible ? ' selected' : '') . '>' . WorkPackageMonitoringView::escape($name) . '</option>';
}
echo "</select></div><div class='col-md-2'><button class='btn btn-primary w-100'><i class='ti ti-filter me-1'></i>Filtrar</button></div></div></form>";
WorkPackageMonitoringView::renderStatusCards($rows);
WorkPackageMonitoringView::renderRows($rows, true);
echo '</div>';
Html::footer();
