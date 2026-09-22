<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\TimeManagementService;

Session::checkLoginUser();
AccessPolicy::check(DemandasProfile::VIEW_TIME_PORTAL);
AccessPolicy::check(DemandasProfile::VIEW_OWN_ATTENDANCE);

$currentUserId = (int) Session::getLoginUserID();
$targetUserId = (int) ($_GET['users_id'] ?? $currentUserId);
if ($targetUserId !== $currentUserId && !AccessPolicy::has(DemandasProfile::VIEW_TEAM_ATTENDANCE)) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}
$service = new TimeManagementService();
$ledger = $service->bankLedger($targetUserId);
$db = DBConnection::getReadConnection();
$users = [];
if (AccessPolicy::has(DemandasProfile::VIEW_TEAM_ATTENDANCE)) {
    foreach ($db->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1], 'ORDER' => ['realname ASC', 'firstname ASC']]) as $user) {
        $users[] = $user;
    }
}

function timeAuditEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function timeAuditBalance(int $minutes): string
{
    $signal = $minutes > 0 ? '+' : ($minutes < 0 ? '-' : '');
    $minutes = abs($minutes);
    return $signal . intdiv($minutes, 60) . 'h' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'min';
}

Html::header('Auditoria do Banco de Horas', $_SERVER['PHP_SELF'], 'management', GlpiPlugin\Demandas\WorkforceHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4'><div><h1 class='mb-1'>Auditoria do banco de horas</h1><p class='text-muted mb-0'>Extrato dos créditos e débitos que efetivamente compõem o saldo.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/timeclock.php?users_id=" . $targetUserId . "'><i class='ti ti-calendar-time me-1'></i>Voltar ao calendário</a></div>";

if ($users !== []) {
    echo "<form method='get' class='card card-body mb-4'><div class='row g-3 align-items-end'><div class='col-md-5'><label class='form-label'>Usuário</label><select class='form-select' name='users_id'>";
    foreach ($users as $user) {
        $name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];
        echo "<option value='" . (int) $user['id'] . "'" . ((int) $user['id'] === $targetUserId ? ' selected' : '') . ">" . timeAuditEsc($name) . "</option>";
    }
    echo "</select></div><div class='col-md-2'><button class='btn btn-primary w-100'>Consultar</button></div></div></form>";
}

$historical = (int) $ledger['historical_minutes'];
$current = (int) $ledger['current_minutes'];
echo "<div class='row row-deck row-cards mb-4'><div class='col-sm-6 col-lg-4'><div class='card'><div class='card-body'><div class='subheader'>Saldo histórico</div><div class='h1 mb-0 text-" . ($historical < 0 ? 'danger' : ($historical > 0 ? 'success' : 'secondary')) . "'>" . timeAuditBalance($historical) . "</div><div class='text-muted small mt-2'>Data-base: " . date('d/m/Y', strtotime((string) $ledger['start_date'])) . "</div></div></div></div><div class='col-sm-6 col-lg-4'><div class='card'><div class='card-body'><div class='subheader'>Saldo atual</div><div class='h1 mb-0 text-" . ($current < 0 ? 'danger' : ($current > 0 ? 'success' : 'secondary')) . "'>" . timeAuditBalance($current) . "</div><div class='text-muted small mt-2'>Inclui somente fatos que incidiram no banco.</div></div></div></div><div class='col-sm-6 col-lg-4'><div class='card'><div class='card-body'><div class='subheader'>Lançamentos no extrato</div><div class='h1 mb-0'>" . max(0, count((array) $ledger['entries']) - 1) . "</div><div class='text-muted small mt-2'>Feriados e dias não úteis não geram lançamento.</div></div></div></div></div>";

echo "<div class='card'><div class='card-header'><h2 class='card-title'>Extrato detalhado</h2></div><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Evento</th><th>Detalhes</th><th class='text-end'>Crédito</th><th class='text-end'>Débito</th><th class='text-end'>Saldo acumulado</th></tr></thead><tbody>";
foreach ((array) $ledger['entries'] as $entry) {
    $minutes = (int) $entry['minutes'];
    $neutral = !empty($entry['neutral']);
    echo "<tr><td>" . date('d/m/Y', strtotime((string) $entry['date'])) . "</td><td>" . timeAuditEsc((string) $entry['label']) . "</td><td class='text-muted'>" . timeAuditEsc((string) $entry['details']) . "</td><td class='text-end text-success'>" . ($neutral ? '' : ($minutes > 0 ? timeAuditBalance($minutes) : '—')) . "</td><td class='text-end text-danger'>" . ($neutral ? '' : ($minutes < 0 ? timeAuditBalance($minutes) : '—')) . "</td><td class='text-end fw-bold'>" . timeAuditBalance((int) $entry['balance_after']) . "</td></tr>";
}
echo "</tbody></table></div></div><p class='form-hint mt-3'>Faltas justificadas aparecem no extrato somente para conferência e mantêm crédito e débito vazios. Marcação incompleta e ausência não justificada podem gerar débito; feriados e dias não úteis são neutros.</p></div>";
Html::footer();
