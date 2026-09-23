<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringService;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;

Session::checkLoginUser();
$service = new WorkPackageMonitoringService();
$notifications = $service->notifications((int) Session::getLoginUserID());

Html::header('Alertas de Work Packages', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Alertas de Work Packages</h1><p class='text-muted mb-0'>Alertas gerados quando uma consulta identifica WPs abertas sob sua responsabilidade.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-monitor.php'><i class='ti ti-list-search me-1'></i>Consultar WPs</a></div>";
if ($notifications === []) {
    echo "<div class='alert alert-info'>Ainda não há alertas. Execute a consulta das suas Work Packages para começar.</div>";
} else {
    echo "<div class='card'><div class='list-group list-group-flush'>";
    foreach ($notifications as $notification) {
        $details = (array) ($notification['details'] ?? []);
        $wpId = (int) ($notification['openproject_work_package_id'] ?? 0);
        $attention = (string) ($notification['priority'] ?? '') === 'warning';
        echo "<div class='list-group-item' id='notification-" . (int) $notification['id'] . "'><div class='d-flex gap-3 align-items-start'><span class='avatar " . ($attention ? 'bg-yellow-lt text-yellow' : 'bg-blue-lt text-blue') . "'><i class='ti " . ($attention ? 'ti-alert-triangle' : 'ti-bell') . "'></i></span><div class='flex-fill'><div class='d-flex justify-content-between gap-3'><strong>" . WorkPackageMonitoringView::escape($notification['title']) . "</strong><small class='text-muted text-nowrap'>" . WorkPackageMonitoringView::date((string) ($notification['date_creation'] ?? '')) . "</small></div><div class='text-muted mt-1'>" . WorkPackageMonitoringView::escape($notification['message']) . "</div>";
        if ($wpId > 0) {
            $url = (string) ($details['openproject_url'] ?? '');
            if ($url !== '') {
                echo "<a class='btn btn-sm btn-outline-primary mt-2' target='_blank' rel='noopener' href='" . WorkPackageMonitoringView::escape($url) . "'>Abrir WP #{$wpId} <i class='ti ti-external-link'></i></a>";
            }
        }
        if (!(int) ($notification['is_read'] ?? 0)) {
            echo " <form class='d-inline' method='post' action='/plugins/demandas/front/work-package-notification.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='notification_id' value='" . (int) $notification['id'] . "'><button class='btn btn-sm btn-outline-secondary mt-2'>Marcar como lida</button></form>";
        }
        echo '</div></div></div>';
    }
    echo '</div></div>';
}
echo '</div>';
Html::footer();
