<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;
use GlpiPlugin\Demandas\WorkPackageMonitoringList;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_DASHBOARD);
$service = new WorkPackageMonitoringService();
$filters = WorkPackageMonitoringList::filters($_GET);
$filters['per_page'] = WorkPackageMonitoringList::pageSize($_GET);
$allRows = $service->consolidatedRows();
$rows = $service->filterRows($allRows, $filters);
$pagination = WorkPackageMonitoringList::paginate($rows, $_GET);

Html::header('Consolidado de Work Packages', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Consolidado de Work Packages</h1><p class='text-muted mb-0'>Visão gerencial formada pelas consultas pessoais executadas pelos usuários.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-monitor.php'><i class='ti ti-user me-1'></i>Minhas WPs</a></div>";
WorkPackageMonitoringView::renderStatusCards($allRows, $filters);
WorkPackageMonitoringView::renderFilters($service->filterOptions($allRows), $filters, $pagination['per_page']);
WorkPackageMonitoringView::renderExports($filters, true);
WorkPackageMonitoringView::renderPagination($pagination, $filters);
WorkPackageMonitoringView::renderRows($pagination['rows'], true);
if ($pagination['pages'] > 1) {
    WorkPackageMonitoringView::renderPagination($pagination, $filters);
}
echo '</div>';
Html::footer();
