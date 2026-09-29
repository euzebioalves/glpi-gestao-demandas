<?php

declare(strict_types=1);

require_once '/var/www/glpi/vendor/autoload.php';

use Glpi\Application\Environment;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

$environment = null;
foreach (Environment::cases() as $candidate) {
    if (stripos($candidate->name, 'prod') !== false || stripos((string) $candidate->value, 'prod') !== false) {
        $environment = $candidate;
        break;
    }
}
$environment ??= Environment::cases()[0];
$kernel = new Kernel($environment->value, false);
$kernel->boot();

global $DB;
$DB = DBConnection::getReadConnection();
if ($DB === null) {
    throw new RuntimeException('Não foi possível inicializar o banco da base E2E.');
}

$password = (string) getenv('E2E_GLPI_FIXTURE_PASSWORD');
if ($password === '') {
    throw new RuntimeException('A senha fictícia da base E2E não foi informada.');
}

function e2eProfileId(string $name): int
{
    global $DB;
    foreach ($DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_profiles',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1,
    ]) as $row) {
        return (int) $row['id'];
    }
    throw new RuntimeException("Perfil base não encontrado: {$name}.");
}

function e2eProfile(string $name, int $sourceProfileId): int
{
    global $DB;
    foreach ($DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_profiles',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1,
    ]) as $row) {
        return (int) $row['id'];
    }

    $profile = new Profile();
    $id = (int) $profile->add([
        'name' => $name,
        'interface' => 'central',
        'is_default' => 0,
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Não foi possível criar o perfil {$name}.");
    }
    $DB->doQuery(sprintf(
        'INSERT IGNORE INTO glpi_profilerights (profiles_id, name, rights) SELECT %d, name, rights FROM glpi_profilerights WHERE profiles_id = %d',
        $id,
        $sourceProfileId
    ));
    return $id;
}

function e2eUser(string $login, string $label, string $password, int $profileId): int
{
    $user = new User();
    $found = $user->find(['name' => $login], [], 1);
    if ($found === []) {
        $userId = (int) $user->add([
            'name' => $login,
            'firstname' => 'E2E',
            'realname' => $label,
            'password' => $password,
            'password2' => $password,
            'is_active' => 1,
        ]);
        if ($userId <= 0) {
            throw new RuntimeException("Não foi possível criar o usuário {$login}.");
        }
    } else {
        $userId = (int) array_key_first($found);
    }

    $profileUser = new Profile_User();
    if ($profileUser->find(['users_id' => $userId, 'profiles_id' => $profileId, 'entities_id' => 0], [], 1) === []) {
        $profileUser->add([
            'users_id' => $userId,
            'profiles_id' => $profileId,
            'entities_id' => 0,
            'is_recursive' => 1,
            'is_dynamic' => 0,
        ]);
    }

    // User::add() associa o perfil padrão Self-Service. A preferência do
    // usuário precisa apontar para o perfil E2E para que a sessão do browser
    // não selecione automaticamente o perfil padrão no login.
    $user->getFromDB($userId);
    $user->update([
        'id' => $userId,
        'profiles_id' => $profileId,
        'entities_id' => 0,
    ]);
    return $userId;
}

function e2eDemandasRights(int $profileId, array $enabled): void
{
    foreach (DemandasProfile::definitions() as $right => $_label) {
        DemandasProfile::setRight($profileId, $right, in_array($right, $enabled, true));
    }
}

function e2eTicket(int $requesterId): int
{
    global $DB;
    $title = 'E2E — pendência local de integração';
    $ticket = new Ticket();
    $found = $ticket->find(['name' => $title, 'entities_id' => 0], [], 1);
    if ($found !== []) {
        return (int) array_key_first($found);
    }

    $ticketId = (int) $ticket->add([
        'name' => $title,
        'content' => 'Chamado estritamente fictício para validação automatizada da Central de Pendências.',
        'entities_id' => 0,
        'status' => 2,
        'type' => 2,
        'urgency' => 3,
        'impact' => 3,
        'priority' => 3,
        '_users_id_requester' => $requesterId,
    ]);
    if ($ticketId <= 0) {
        throw new RuntimeException('Não foi possível criar o chamado fictício E2E.');
    }

    $oldDate = date('Y-m-d H:i:s', strtotime('-10 days'));
    $DB->insert('glpi_plugin_demandas_links', [
        'tickets_id' => $ticketId,
        'openproject_work_package_id' => 990001,
        'openproject_project_name' => 'E2E local',
        'openproject_status' => 'Novo',
        'public_phase' => 'Em análise',
        'last_synced_at' => $oldDate,
        'date_creation' => $oldDate,
        'date_mod' => $oldDate,
    ]);
    return $ticketId;
}

$superAdmin = e2eProfileId('Super-Admin');
$technician = e2eProfileId('Technician');
$selfService = e2eProfileId('Self-Service');

$adminProfile = $superAdmin;
$operatorProfile = $superAdmin;
$viewerProfile = e2eProfile('E2E — Visualizador', $technician);
$restrictedProfile = e2eProfile('E2E — Restrito', $selfService);

$allRights = array_keys(DemandasProfile::definitions());
e2eDemandasRights($superAdmin, $allRights);
e2eDemandasRights($viewerProfile, [
    DemandasProfile::VIEW_PUBLIC,
    DemandasProfile::VIEW_TECHNICAL,
    DemandasProfile::VIEW_OPERATIONAL_HEALTH,
]);
e2eDemandasRights($restrictedProfile, []);

e2eUser('demandas.e2e.admin', 'Administrador', $password, $adminProfile);
$operatorId = e2eUser('demandas.e2e.operator', 'Operador', $password, $operatorProfile);
e2eUser('demandas.e2e.viewer', 'Visualizador', $password, $viewerProfile);
e2eUser('demandas.e2e.restricted', 'Restrito', $password, $restrictedProfile);
$ticketId = e2eTicket($operatorId);

echo 'E2E_GLPI_BOOTSTRAP=' . json_encode([
    'status' => 'ready',
    'ticket_id' => $ticketId,
    'users' => 4,
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
