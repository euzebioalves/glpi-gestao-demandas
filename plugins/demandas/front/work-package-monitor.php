<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;
use GlpiPlugin\Demandas\WorkPackageMonitoringList;

Session::checkLoginUser();
$userId = (int) Session::getLoginUserID();
$service = new WorkPackageMonitoringService();
$filters = WorkPackageMonitoringList::filters($_GET);
$filters['per_page'] = WorkPackageMonitoringList::pageSize($_GET);
$allRows = $service->userRows($userId);
$rows = $service->filterRows($allRows, $filters);
$pagination = WorkPackageMonitoringList::paginate($rows, $_GET);

Html::header('Minhas Work Packages', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
$consolidatedButton = DemandasProfile::has(DemandasProfile::VIEW_DASHBOARD)
    ? "<a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-consolidated.php'><i class='ti ti-layout-dashboard me-1'></i>Consolidado geral</a>"
    : '';
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Minhas Work Packages abertas</h1><p class='text-muted mb-0'>Consulte User Stories, Épicos e Bugs abertos pelos quais você é responsável no OpenProject.</p></div><div class='d-flex flex-wrap gap-2 align-items-center'>" . $consolidatedButton . "<form id='demandas-monitor-form' method='post' action='/plugins/demandas/front/work-package-monitor.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><button id='demandas-monitor-submit' class='btn btn-primary'><i class='ti ti-refresh me-1'></i>Consultar minhas WPs</button></form></div></div>";
echo "<div id='demandas-monitor-loading' class='card card-body mb-4 d-none' role='status' aria-live='polite'><div class='d-flex align-items-center gap-2 mb-2'><span class='spinner-border spinner-border-sm text-primary' aria-hidden='true'></span><strong>Consultando Work Packages no OpenProject…</strong></div><div class='progress'><div class='progress-bar progress-bar-striped progress-bar-animated w-100' role='progressbar' aria-label='Consulta em andamento'></div></div><div class='form-hint mt-2'>O OpenProject informa o total somente durante a resposta da consulta; por isso este indicador não exibe uma porcentagem imprecisa.</div></div>";
if (!DemandasConfig::hasPersonalToken($userId)) {
    echo "<div class='alert alert-warning'>Seu token pessoal do OpenProject ainda não foi configurado. <a class='alert-link' href='/front/preference.php?forcetab=" . rawurlencode('GlpiPlugin\\Demandas\\OpenProjectPersonalToken$1') . "'>Configurar token</a>.</div>";
}
if ($allRows !== []) {
    WorkPackageMonitoringView::renderStatusCards($allRows, $filters);
    echo "<p class='text-muted small'>Apenas WPs abertas da última consulta são exibidas. Os vínculos incluem o campo Atividade DevOps, acompanhamentos e vínculos já registrados pelo plugin.</p>";
    WorkPackageMonitoringView::renderFilters($service->filterOptions($allRows), $filters, $pagination['per_page']);
}
WorkPackageMonitoringView::renderExports($filters);
WorkPackageMonitoringView::renderPagination($pagination, $filters);
WorkPackageMonitoringView::renderRows($pagination['rows'], false, DemandasProfile::has(DemandasProfile::PREPARE_AI_CONTEXT));
if ($pagination['pages'] > 1) {
    WorkPackageMonitoringView::renderPagination($pagination, $filters);
}
echo "<script>document.getElementById('demandas-monitor-form')?.addEventListener('submit',function(event){if(this.dataset.submitting==='1')return;event.preventDefault();this.dataset.submitting='1';const button=document.getElementById('demandas-monitor-submit');button.disabled=true;button.innerHTML='<span class=\"spinner-border spinner-border-sm me-1\" aria-hidden=\"true\"></span>Consultando…';document.getElementById('demandas-monitor-loading')?.classList.remove('d-none');requestAnimationFrame(()=>this.submit())});</script></div>";
Html::footer();
