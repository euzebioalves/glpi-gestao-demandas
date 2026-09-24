<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageAnalysisService;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;
use GlpiPlugin\Demandas\WorkPackageMonitoringView;

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Glpi\Exception\Http\BadRequestHttpException();
}
$workPackageId = (int) ($_POST['wp'] ?? 0);
$ticketId = (int) ($_POST['ticket'] ?? 0);

try {
    if ((string) ($_POST['external_consent'] ?? '') !== '1') {
        throw new RuntimeException('Confirme que está autorizado a compartilhar os dados selecionados antes de gerar o contexto.');
    }
    $result = (new WorkPackageAnalysisService())->prepare(
        (int) Session::getLoginUserID(),
        $workPackageId,
        $ticketId,
        (array) ($_POST['sections'] ?? []),
        (array) ($_POST['attachments'] ?? []),
    );
    Html::header('Contexto preparado para IA', $_SERVER['PHP_SELF'], 'management', WorkPackageMonitoringHub::class);
    echo "<div class='container-xl'><div class='d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4'><div><h1 class='mb-1'>Contexto preparado</h1><p class='text-muted mb-0'>Nenhum dado foi enviado pelo plugin a serviços externos.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/work-package-analysis.php?wp=" . (int) $result['work_package_id'] . '&ticket=' . (int) $result['ticket_id'] . "'><i class='ti ti-arrow-left me-1'></i>Revisar seleção</a></div>";
    echo "<div class='card'><div class='card-body'><label class='form-label' for='demandas-ai-prompt'>Prompt para sua IA</label><textarea class='form-control font-monospace' id='demandas-ai-prompt' rows='24' readonly>" . WorkPackageMonitoringView::escape($result['prompt']) . "</textarea><div class='d-flex flex-wrap gap-2 mt-3'><button class='btn btn-primary' type='button' id='demandas-copy-ai'><i class='ti ti-copy me-1'></i>Copiar contexto</button><button class='btn btn-outline-primary' type='button' id='demandas-open-chatgpt'><i class='ti ti-brand-openai me-1'></i>Abrir ChatGPT</button></div>";
    if ($result['attachments'] !== []) {
        echo "<hr><h2 class='h3'>Extração local dos anexos</h2><ul class='mb-0'>";
        foreach ($result['attachments'] as $attachment) {
            echo '<li>' . WorkPackageMonitoringView::escape($attachment['name']) . ' — ' . ($attachment['extracted'] ? 'texto incluído' : 'não foi possível extrair texto') . '</li>';
        }
        echo '</ul>';
    }
    echo "</div></div><script>const prompt=document.getElementById('demandas-ai-prompt');const copy=async()=>{try{await navigator.clipboard.writeText(prompt.value)}catch(_){prompt.select();document.execCommand('copy')}};document.getElementById('demandas-copy-ai').onclick=copy;document.getElementById('demandas-open-chatgpt').onclick=async()=>{await copy();window.open('https://chatgpt.com/','_blank','noopener')};</script></div>";
    Html::footer();
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
    Html::redirect('/plugins/demandas/front/work-package-analysis.php?wp=' . $workPackageId . '&ticket=' . $ticketId);
}
