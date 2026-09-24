<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringList;
use GlpiPlugin\Demandas\WorkPackageMonitoringExport;

Session::checkLoginUser();
$scope = $_GET['scope'] ?? 'mine';
$format = $_GET['format'] ?? '';
if (!in_array($scope, ['mine', 'consolidated'], true) || !in_array($format, ['pdf', 'xlsx'], true)) {
    throw new Glpi\Exception\Http\BadRequestHttpException('Exportação inválida.');
}
$consolidated = $scope === 'consolidated';
if ($consolidated) {
    DemandasProfile::checkRight(DemandasProfile::VIEW_DASHBOARD);
    DemandasProfile::checkRight(DemandasProfile::EXPORT_DASHBOARD);
}
$service = new WorkPackageMonitoringService();
// Never accept a user ID from the request, and never slice exported rows.
$rows = $consolidated ? $service->consolidatedRows() : $service->userRows((int) Session::getLoginUserID());
$filters = WorkPackageMonitoringList::filters($_GET);
$rows = $service->filterRows($rows, $filters);
header('Cache-Control: no-store');
WorkPackageMonitoringExport::download($format, $rows, $filters, $consolidated);
exit;
