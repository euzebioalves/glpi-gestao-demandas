<?php

declare(strict_types=1);

require_once '/var/www/glpi/vendor/autoload.php';

$environments = \Glpi\Application\Environment::cases();
$productionEnvironment = null;
foreach ($environments as $environment) {
    if (
        stripos($environment->name, 'prod') !== false
        || stripos((string) $environment->value, 'prod') !== false
    ) {
        $productionEnvironment = $environment;
        break;
    }
}
$productionEnvironment ??= $environments[0];

$kernel = new \Glpi\Kernel\Kernel($productionEnvironment->value, false);
$kernel->boot();

global $DB;
$DB = DBConnection::getReadConnection();

if ($DB === null) {
    throw new RuntimeException('Não foi possível inicializar a conexão com o banco do GLPI.');
}

$result = [
    'application' => 'GLPI',
    'created' => [],
    'reused' => [],
    'warnings' => [],
];

function findProfileId(string $name): ?int
{
    global $DB;
    $iterator = $DB->request([
        'SELECT' => ['id'],
        'FROM' => 'glpi_profiles',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1,
    ]);
    foreach ($iterator as $row) {
        return (int) $row['id'];
    }
    return null;
}

function ensureProfile(string $name, int $sourceProfileId, string $interface, array &$result): int
{
    global $DB;
    $existing = findProfileId($name);
    if ($existing !== null) {
        $result['reused'][] = "profile:$existing:$name";
        return $existing;
    }

    $profile = new Profile();
    $profileId = (int) $profile->add([
        'name' => $name,
        'interface' => $interface,
        'is_default' => 0,
    ]);
    if ($profileId <= 0) {
        throw new RuntimeException("Não foi possível criar o perfil $name.");
    }

    $query = sprintf(
        'INSERT IGNORE INTO glpi_profilerights (profiles_id, name, rights) SELECT %d, name, rights FROM glpi_profilerights WHERE profiles_id = %d',
        $profileId,
        $sourceProfileId
    );
    $DB->doQuery($query);
    $result['created'][] = "profile:$profileId:$name";
    return $profileId;
}

function ensureUser(string $login, string $firstName, string $lastName, string $email, string $password, int $profileId, array &$result): int
{
    $user = new User();
    $found = $user->find(['name' => $login]);
    if (!empty($found)) {
        $userId = (int) array_key_first($found);
        $result['reused'][] = "user:$userId:$login";
    } else {
        $userId = (int) $user->add([
            'name' => $login,
            'firstname' => $firstName,
            'realname' => $lastName,
            'password' => $password,
            'password2' => $password,
            'is_active' => 1,
            '_useremails' => [$email],
        ]);
        if ($userId <= 0) {
            throw new RuntimeException("Não foi possível criar o usuário $login.");
        }
        $result['created'][] = "user:$userId:$login";
    }

    $profileUser = new Profile_User();
    $links = $profileUser->find([
        'users_id' => $userId,
        'profiles_id' => $profileId,
        'entities_id' => 0,
    ]);
    if (empty($links)) {
        $profileUser->add([
            'users_id' => $userId,
            'profiles_id' => $profileId,
            'entities_id' => 0,
            'is_recursive' => 1,
            'is_dynamic' => 0,
        ]);
    }
    return $userId;
}

function ensureDemandasRights(int $profileId, array $enabledRights): void
{
    $definitions = [
        'demandas_view_public',
        'demandas_view_technical',
        'demandas_view_history',
        'demandas_create_workpackage',
        'demandas_sync_workpackage',
        'demandas_manage_config',
        'demandas_view_dashboard',
        'demandas_export_dashboard',
    ];
    $profileRight = new ProfileRight();
    foreach ($definitions as $right) {
        $found = $profileRight->find(['profiles_id' => $profileId, 'name' => $right]);
        $value = in_array($right, $enabledRights, true) ? READ : 0;
        if ($found === []) {
            $profileRight->add(['profiles_id' => $profileId, 'name' => $right, 'rights' => $value]);
        } else {
            $id = (int) array_key_first($found);
            if ((int) ($found[$id]['rights'] ?? 0) !== $value) {
                $profileRight->update(['id' => $id, 'rights' => $value]);
            }
        }
    }

    if (in_array('demandas_view_public', $enabledRights, true)) {
        $found = $profileRight->find([
            'profiles_id' => $profileId,
            'name' => ITILFollowup::$rightname,
        ]);
        if ($found === []) {
            $profileRight->add([
                'profiles_id' => $profileId,
                'name' => ITILFollowup::$rightname,
                'rights' => ITILFollowup::SEEPUBLIC,
            ]);
        } else {
            $id = (int) array_key_first($found);
            $current = (int) ($found[$id]['rights'] ?? 0);
            $required = $current | ITILFollowup::SEEPUBLIC;
            if ($required !== $current) {
                $profileRight->update(['id' => $id, 'rights' => $required]);
            }
        }
    }
}

try {
    $requirementsProfileId = ensureProfile('Requisitos — Gestão de Demandas', 6, 'central', $result);
    $customerProfileId = ensureProfile('Cliente — Gestão de Demandas', 1, 'helpdesk', $result);
    ensureDemandasRights($requirementsProfileId, [
        'demandas_view_public',
        'demandas_view_technical',
        'demandas_view_history',
        'demandas_create_workpackage',
        'demandas_sync_workpackage',
        'demandas_view_dashboard',
        'demandas_export_dashboard',
    ]);
    ensureDemandasRights($customerProfileId, ['demandas_view_public']);

    $requirementsPassword = getenv('MVP_GLPI_REQUIREMENTS_PASSWORD');
    $customerPassword = getenv('MVP_GLPI_CUSTOMER_PASSWORD');
    if (!$requirementsPassword || !$customerPassword) {
        throw new RuntimeException('As senhas dos usuários de teste não foram fornecidas.');
    }

    $requirementsUserId = ensureUser(
        'requisitos.mvp',
        'Requisitos',
        'MVP',
        'requisitos.mvp@mvp.local',
        $requirementsPassword,
        $requirementsProfileId,
        $result
    );
    $customerUserId = ensureUser(
        'cliente.mvp',
        'Cliente',
        'MVP',
        'cliente.mvp@mvp.local',
        $customerPassword,
        $customerProfileId,
        $result
    );

    $ticket = new Ticket();
    $tickets = $ticket->find(['name' => '[MVP] Solicitação para integração GLPI e OpenProject']);
    if (empty($tickets)) {
        $ticketId = (int) $ticket->add([
            'name' => '[MVP] Solicitação para integração GLPI e OpenProject',
            'content' => 'Ticket fictício criado pelo bootstrap para validar a criação e a rastreabilidade de Work Packages.',
            'entities_id' => 0,
            'status' => 1,
            'type' => 2,
            'urgency' => 3,
            'impact' => 3,
            'priority' => 3,
            '_users_id_requester' => $customerUserId,
            '_users_id_assign' => $requirementsUserId,
        ]);
        if ($ticketId <= 0) {
            $result['warnings'][] = 'Os usuários e perfis foram criados, mas o ticket fictício não pôde ser criado.';
        } else {
            $result['created'][] = "ticket:$ticketId";
            $result['ticket_id'] = $ticketId;
        }
    } else {
        $ticketId = (int) array_key_first($tickets);
        $result['reused'][] = "ticket:$ticketId";
        $result['ticket_id'] = $ticketId;
    }

    $result['requirements_profile_id'] = $requirementsProfileId;
    $result['customer_profile_id'] = $customerProfileId;
    $result['requirements_user_id'] = $requirementsUserId;
    $result['customer_user_id'] = $customerUserId;
    echo 'MVP_RESULT=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'MVP_ERROR=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
