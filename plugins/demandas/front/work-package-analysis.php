<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageAnalysisService;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;

Session::checkLoginUser();
$workPackageId = (int) ($_GET['wp'] ?? 0);
$ticketId = (int) ($_GET['ticket'] ?? 0);
$service = new WorkPackageAnalysisService();

try {
    $context = $service->context((int) Session::getLoginUserID(), $workPackageId, $ticketId);
    /** @var Ticket $ticket */
    $ticket = $context['ticket'];
    $details = (array) (($context['monitor']['details'] ?? []));
    $ticketId = $ticket->getID();
} catch (Throwable $exception) {
    Html::header('Preparar análise em IA', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
    echo "<div class='container-xl'><div class='alert alert-danger'>" . WorkPackageMonitoringView::escape($exception->getMessage()) . "</div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-monitor.php'>Voltar para as Work Packages</a></div>";
    Html::footer();
    return;
}

$defaults = ['wp', 'wp_status', 'wp_responsible', 'wp_customer', 'ticket_title', 'ticket_number', 'ticket_status', 'links', 'ticket_summary'];
Html::header('Preparar análise em IA', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Preparar análise em IA</h1><p class='text-muted mb-0'>WP #" . (int) ($details['id'] ?? $workPackageId) . ' e chamado #' . $ticketId . "</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-monitor.php'><i class='ti ti-arrow-left me-1'></i>Voltar</a></div>";
echo "<form class='card' method='post' action='/plugins/demandas/front/work-package-analysis.form.php'><div class='card-body'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='wp' value='" . (int) $workPackageId . "'>";
if (count($context['ticket_ids']) > 1) {
    echo "<div class='mb-4'><label class='form-label' for='ticket-selector'>Chamado vinculado</label><select class='form-select' id='ticket-selector' onchange='window.location.href=this.value'>";
    foreach ($context['ticket_ids'] as $id) {
        $url = '/plugins/demandas/front/work-package-analysis.php?wp=' . (int) $workPackageId . '&ticket=' . (int) $id;
        echo "<option value='" . WorkPackageMonitoringView::escape($url) . "'" . ((int) $id === $ticketId ? ' selected' : '') . '>#' . (int) $id . '</option>';
    }
    echo "</select><div class='form-hint'>Ao mudar o chamado, o conteúdo e os anexos disponíveis serão recarregados.</div></div>";
}
echo "<input type='hidden' name='ticket' value='" . $ticketId . "'>";
echo "<div class='alert alert-warning'><i class='ti ti-shield-lock me-1'></i>O plugin prepara o conteúdo localmente. Ao copiar e colar o resultado em uma IA externa, você será responsável por esse compartilhamento.</div><h2 class='h3'>Informações incluídas</h2><div class='row g-3 mb-4'>";
foreach (WorkPackageAnalysisService::SECTIONS as $key => $label) {
    if ($key === 'attachments') {
        continue;
    }
    $checked = in_array($key, $defaults, true) ? ' checked' : '';
    $sensitive = in_array($key, ['ticket_description', 'ticket_followups'], true) ? " <span class='badge bg-yellow-lt text-yellow'>Sensível</span>" : '';
    echo "<div class='col-md-6'><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='sections[]' value='" . WorkPackageMonitoringView::escape($key) . "'" . $checked . "><span class='form-check-label'>" . WorkPackageMonitoringView::escape($label) . $sensitive . '</span></label></div>';
}
echo "</div><h2 class='h3'>Anexos</h2>";
if ($context['attachments'] === []) {
    echo "<p class='text-muted'>Não há anexos acessíveis no chamado ou em seus acompanhamentos públicos.</p>";
} else {
    echo "<label class='form-check form-switch mb-3'><input class='form-check-input' type='checkbox' name='sections[]' value='attachments'><span class='form-check-label'>Incluir conteúdo extraído dos anexos selecionados <span class='badge bg-yellow-lt text-yellow'>Sensível</span></span></label><div class='list-group mb-4'>";
    foreach ($context['attachments'] as $attachment) {
        $size = number_format(((int) ($attachment['size'] ?? 0)) / 1024, 1, ',', '.');
        echo "<label class='list-group-item d-flex gap-3 align-items-center'><input class='form-check-input flex-shrink-0' type='checkbox' name='attachments[]' value='" . (int) $attachment['id'] . "'><span class='flex-fill'>" . WorkPackageMonitoringView::escape($attachment['name']) . "<small class='d-block text-muted'>" . WorkPackageMonitoringView::escape($attachment['mime']) . ' · ' . $size . " KB</small></span></label>";
    }
    echo '</div>';
}
echo "<label class='form-check mb-4'><input class='form-check-input' type='checkbox' name='external_consent' value='1' required><span class='form-check-label'>Confirmo que revisei os dados selecionados e estou autorizado a copiá-los para uma IA externa.</span></label><button class='btn btn-primary'><i class='ti ti-file-text me-1'></i>Gerar contexto para análise</button></div></form></div>";
Html::footer();
