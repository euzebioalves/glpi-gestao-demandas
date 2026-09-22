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
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'justified', 'unjustified'], true)) {
    $filter = 'all';
}
$service = new TimeManagementService();
$absences = $service->absences($targetUserId, $filter);
$db = DBConnection::getReadConnection();
$users = [];
if (AccessPolicy::has(DemandasProfile::VIEW_TEAM_ATTENDANCE)) {
    foreach ($db->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1], 'ORDER' => ['realname ASC', 'firstname ASC']]) as $user) {
        $users[] = $user;
    }
}

function absenceEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function absenceDuration(int $minutes): string
{
    return intdiv($minutes, 60) . 'h' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'min';
}

Html::header('Minhas ausências', $_SERVER['PHP_SELF'], 'management', GlpiPlugin\Demandas\WorkforceHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4'><div><h1 class='mb-1'>Ausências</h1><p class='text-muted mb-0'>Consulte faltas registradas e baixe os comprovantes das ausências justificadas.</p></div><a class='btn btn-outline-primary' href='/plugins/demandas/front/timeclock.php?users_id=" . $targetUserId . "'><i class='ti ti-calendar-time me-1'></i>Voltar ao calendário</a></div>";

echo "<form method='get' class='card card-body mb-4'><div class='row g-3 align-items-end'>";
if ($users !== []) {
    echo "<div class='col-md-4'><label class='form-label'>Usuário</label><select class='form-select' name='users_id'>";
    foreach ($users as $user) {
        $name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];
        echo "<option value='" . (int) $user['id'] . "'" . ((int) $user['id'] === $targetUserId ? ' selected' : '') . ">" . absenceEsc($name) . "</option>";
    }
    echo "</select></div>";
}
echo "<div class='col-md-4'><label class='form-label'>Situação</label><select class='form-select' name='filter'><option value='all'" . ($filter === 'all' ? ' selected' : '') . ">Todas</option><option value='justified'" . ($filter === 'justified' ? ' selected' : '') . ">Justificadas</option><option value='unjustified'" . ($filter === 'unjustified' ? ' selected' : '') . ">Não justificadas</option></select></div><div class='col-md-2'><button class='btn btn-primary w-100'>Filtrar</button></div></div></form>";

echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Situação</th><th>Período</th><th>Motivo / observação</th><th>Comprovantes</th></tr></thead><tbody>";
foreach ($absences as $absence) {
    $justified = (string) $absence['kind'] === 'justified';
    $period = (int) ($absence['is_full_day'] ?? 1) === 1
        ? 'Dia inteiro (' . absenceDuration((int) ($absence['minutes'] ?? 0)) . ')'
        : substr((string) ($absence['starts_at'] ?? ''), 0, 5) . ' às ' . substr((string) ($absence['ends_at'] ?? ''), 0, 5) . ' (' . absenceDuration((int) ($absence['minutes'] ?? 0)) . ')';
    echo "<tr><td>" . date('d/m/Y', strtotime((string) $absence['absence_date'])) . "</td><td><span class='badge bg-" . ($justified ? 'success' : 'danger') . "-lt'>" . ($justified ? 'Justificada' : 'Não justificada') . "</span></td><td>" . absenceEsc($period) . "</td><td>" . nl2br(absenceEsc((string) ($absence['reason'] ?? '—'))) . "</td><td>";
    if ($justified && !empty($absence['files'])) {
        foreach ($absence['files'] as $file) {
            echo "<a class='d-block' href='/plugins/demandas/front/absence-file.php?id=" . (int) $file['id'] . "&users_id=" . $targetUserId . "'><i class='ti ti-download me-1'></i>" . absenceEsc((string) $file['original_name']) . "</a>";
        }
    } else {
        echo "<span class='text-muted'>—</span>";
    }
    echo "</td></tr>";
}
if ($absences === []) {
    echo "<tr><td colspan='5' class='text-center text-muted py-4'>Nenhuma ausência encontrada para o filtro selecionado.</td></tr>";
}
echo "</tbody></table></div></div></div>";
Html::footer();
