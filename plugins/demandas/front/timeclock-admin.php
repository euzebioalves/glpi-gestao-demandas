<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\TimeManagementService;

Session::checkLoginUser();
AccessPolicy::check(DemandasProfile::VIEW_TIME_PORTAL);

$isSuperAdmin = DemandasConfig::isActiveSuperAdmin();
$canAccess = AccessPolicy::has(DemandasProfile::MANAGE_TIME_ACCESS);
if (!$isSuperAdmin && !$canAccess) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}

$service = new TimeManagementService();
$db = DBConnection::getReadConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_holiday'])) {
            $service->saveHoliday($_POST);
            Session::addMessageAfterRedirect('Feriado ou dia não útil salvo. Ele não incidirá no banco de horas.', true, INFO);
        } elseif (isset($_POST['delete_holiday'])) {
            $service->deleteHoliday((int) ($_POST['id'] ?? 0));
            Session::addMessageAfterRedirect('Feriado ou dia não útil removido.', true, INFO);
        } elseif (isset($_POST['save_access'])) {
            AccessPolicy::check(DemandasProfile::MANAGE_TIME_ACCESS);
            $userId = (int) ($_POST['users_id'] ?? 0);
            $right = (string) ($_POST['right_name'] ?? '');
            if ($userId <= 0 || !array_key_exists($right, DemandasProfile::definitions())) {
                throw new RuntimeException('Usuário ou permissão inválidos.');
            }
            $existing = null;
            foreach ($db->request(['FROM' => 'glpi_plugin_demandas_user_rights', 'WHERE' => ['users_id' => $userId, 'right_name' => $right], 'LIMIT' => 1]) as $row) {
                $existing = $row;
            }
            $data = ['users_id' => $userId, 'right_name' => $right, 'decision' => (int) ($_POST['decision'] ?? 0), 'date_mod' => date('Y-m-d H:i:s')];
            if ($existing !== null) {
                $db->update('glpi_plugin_demandas_user_rights', $data, ['id' => (int) $existing['id']]);
            } else {
                $data['date_creation'] = date('Y-m-d H:i:s');
                $db->insert('glpi_plugin_demandas_user_rights', $data);
            }
            Session::addMessageAfterRedirect('Exceção individual salva.', true, INFO);
        }
    } catch (Throwable $exception) {
        Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
    }
    Html::redirect('/plugins/demandas/front/timeclock-admin.php');
}

function timeAdminEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}

$holidays = $isSuperAdmin ? $service->holidays() : [];
$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
foreach ($holidays as $holiday) {
    if ((int) $holiday['id'] === $editId) {
        $editing = $holiday;
        break;
    }
}
$editing ??= ['id' => 0, 'name' => '', 'holiday_date' => '', 'scope' => 'national', 'state_code' => '', 'municipality' => '', 'substitute_date' => '', 'notes' => ''];
$scopeLabels = ['international' => 'Internacional', 'national' => 'Nacional', 'state' => 'Estadual', 'municipal' => 'Municipal'];

Html::header('Administração do Ponto', $_SERVER['PHP_SELF'], 'management', GlpiPlugin\Demandas\WorkforceHub::class);
echo "<div class='container-xl'><h1>Administração do ponto</h1><div class='row g-4'>";

if ($isSuperAdmin) {
    echo "<div class='col-12'><div class='alert alert-info'><i class='ti ti-calendar-off me-2'></i><strong>Feriados e dias não úteis:</strong> estes dias ficam neutros no banco de horas, mesmo que existam marcações ou ausências. A configuração é exclusiva do perfil ativo Super-Admin.</div></div>";
    echo "<div class='col-lg-5'><div class='card'><div class='card-header'><h2 class='card-title'>" . ((int) $editing['id'] > 0 ? 'Editar' : 'Adicionar') . " feriado ou dia não útil</h2></div><div class='card-body'><form method='post'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='id' value='" . (int) $editing['id'] . "'><div class='row g-3'>";
    echo "<div class='col-md-8'><label class='form-label'>Nome</label><input class='form-control' name='name' required maxlength='255' value='" . timeAdminEsc((string) $editing['name']) . "'></div>";
    echo "<div class='col-md-4'><label class='form-label'>Data</label><input class='form-control' type='date' name='holiday_date' required value='" . timeAdminEsc((string) $editing['holiday_date']) . "'></div>";
    echo "<div class='col-md-4'><label class='form-label'>Abrangência</label><select class='form-select' name='scope'>";
    foreach ($scopeLabels as $value => $label) {
        echo "<option value='" . $value . "'" . ((string) $editing['scope'] === $value ? ' selected' : '') . ">" . $label . "</option>";
    }
    echo "</select></div><div class='col-md-2'><label class='form-label'>UF</label><input class='form-control' name='state_code' maxlength='2' value='" . timeAdminEsc((string) $editing['state_code']) . "'></div>";
    echo "<div class='col-md-6'><label class='form-label'>Município</label><input class='form-control' name='municipality' maxlength='120' value='" . timeAdminEsc((string) $editing['municipality']) . "'></div>";
    echo "<div class='col-12'><label class='form-label'>Data de compensação não útil <span class='text-muted'>(opcional)</span></label><input class='form-control' type='date' name='substitute_date' value='" . timeAdminEsc((string) $editing['substitute_date']) . "'><div class='form-hint'>Use quando a folga também for transferida para outro dia sem expediente.</div></div>";
    echo "<div class='col-12'><label class='form-label'>Observações</label><textarea class='form-control' rows='3' name='notes'>" . timeAdminEsc((string) $editing['notes']) . "</textarea></div></div>";
    echo "<div class='d-flex gap-2 mt-3'><button class='btn btn-primary' name='save_holiday' value='1'>Salvar</button>";
    if ((int) $editing['id'] > 0) {
        echo "<a class='btn btn-outline-secondary' href='/plugins/demandas/front/timeclock-admin.php'>Cancelar edição</a>";
    }
    echo "</div></form></div></div></div>";
    echo "<div class='col-lg-7'><div class='card'><div class='card-header'><h2 class='card-title'>Dias configurados</h2></div><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Data</th><th>Nome</th><th>Abrangência</th><th>Compensação</th><th></th></tr></thead><tbody>";
    foreach ($holidays as $holiday) {
        $id = (int) $holiday['id'];
        echo "<tr><td>" . date('d/m/Y', strtotime((string) $holiday['holiday_date'])) . "</td><td>" . timeAdminEsc((string) $holiday['name']) . "</td><td>" . timeAdminEsc($scopeLabels[(string) $holiday['scope']] ?? (string) $holiday['scope']) . "</td><td>" . (!empty($holiday['substitute_date']) ? date('d/m/Y', strtotime((string) $holiday['substitute_date'])) : '—') . "</td><td class='text-end'><a class='btn btn-sm btn-outline-primary' href='?edit=" . $id . "'>Editar</a><form class='d-inline ms-1' method='post'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='id' value='" . $id . "'><button class='btn btn-sm btn-outline-danger' name='delete_holiday' value='1' onclick=\"return confirm('Remover este dia configurado?')\">Remover</button></form></td></tr>";
    }
    if ($holidays === []) {
        echo "<tr><td colspan='5' class='text-muted text-center'>Nenhum feriado ou dia não útil configurado.</td></tr>";
    }
    echo "</tbody></table></div></div></div>";
}

if ($canAccess) {
    $rights = [DemandasProfile::VIEW_TIME_PORTAL, DemandasProfile::LOG_OWN_TIME, DemandasProfile::LOG_OTHERS_TIME, DemandasProfile::VIEW_OWN_ATTENDANCE, DemandasProfile::VIEW_TEAM_ATTENDANCE, DemandasProfile::MANAGE_ATTENDANCE, DemandasProfile::MANAGE_HOLIDAYS];
    echo "<div class='col-lg-6'><div class='card'><div class='card-header'><h2 class='card-title'>Exceções individuais de acesso</h2></div><div class='card-body'><p class='text-muted'>A decisão individual prevalece sobre a permissão herdada do perfil.</p><form method='post'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><label class='form-label'>Usuário</label><select class='form-select mb-3' name='users_id'>";
    foreach ($db->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1], 'ORDER' => ['realname ASC']]) as $user) {
        $name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];
        echo "<option value='" . (int) $user['id'] . "'>" . timeAdminEsc($name) . "</option>";
    }
    echo "</select><label class='form-label'>Permissão</label><select class='form-select mb-3' name='right_name'>";
    foreach ($rights as $right) {
        echo "<option value='" . timeAdminEsc($right) . "'>" . timeAdminEsc(DemandasProfile::definitions()[$right]) . "</option>";
    }
    echo "</select><label class='form-label'>Decisão individual</label><select class='form-select mb-3' name='decision'><option value='1'>Permitir</option><option value='0'>Negar</option></select><button class='btn btn-primary' name='save_access' value='1'>Salvar exceção</button></form></div></div></div>";
}

echo "</div></div>";
Html::footer();
