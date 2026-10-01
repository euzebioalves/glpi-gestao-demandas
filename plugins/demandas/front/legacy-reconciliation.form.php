<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\LegacyReconciliationService;

LegacyReconciliationService::checkAccess();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Glpi\Exception\Http\BadRequestHttpException();
$service = new LegacyReconciliationService();
if (($_POST['action'] ?? '') === 'transfer') {
    $pair = is_string($_POST['pair'] ?? null) ? $_POST['pair'] : '';
    if (($_POST['confirm_transfer'] ?? '') !== '1' || !preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $pair, $match)) {
        Session::addMessageAfterRedirect('Confirme a transferência e selecione uma referência válida.', true, ERROR);
    } else {
        try {
            $service->transferConflict((int) $match[1], (int) $match[2]);
            Session::addMessageAfterRedirect("Vínculo local da WP #{$match[2]} transferido para o chamado #{$match[1]}. Nenhuma WP foi alterada no OpenProject.", true, INFO);
        } catch (Glpi\Exception\Http\AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
        } catch (Throwable) {
            Session::addMessageAfterRedirect('Não foi possível transferir o vínculo local. Os vínculos existentes foram preservados.', true, ERROR);
        }
    }
} else {
    $pairs = $_POST['pairs'] ?? [];
    if (($_POST['confirm'] ?? '') !== '1' || !is_array($pairs) || count($pairs) < 1 || count($pairs) > 5) {
        Session::addMessageAfterRedirect('Selecione de uma a cinco referências e confirme a conferência.', true, ERROR);
    } else {
    foreach (array_unique(array_filter($pairs, 'is_string')) as $pair) {
        if (!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $pair, $m)) continue;
        try {
            $result = $service->reconcile((int) $m[1], (int) $m[2]);
            Session::addMessageAfterRedirect($result === 'linked' ? "Chamado #{$m[1]} vinculado à WP #{$m[2]}. Nenhuma WP criada." : 'Vínculo já existente, preservado sem duplicação.', true, INFO);
        } catch (Glpi\Exception\Http\AccessDeniedHttpException $e) {
            throw $e;
        } catch (DomainException $e) {
            Session::addMessageAfterRedirect($e->getMessage(), true, ERROR);
        } catch (Throwable) {
            Session::addMessageAfterRedirect('Não foi possível conciliar a referência. Confira a configuração e tente novamente.', true, ERROR);
        }
    }
    }
}
Html::redirect('/plugins/demandas/front/legacy-reconciliation.php?ticket_scope=' . (($_POST['ticket_scope'] ?? '') === 'all' ? 'all' : 'open'));
