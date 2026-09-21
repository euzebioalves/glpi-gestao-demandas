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

function ensureUser(string $login, string $firstName, string $lastName, string $email, string $password, int $profileId, int $entityId, array &$result): int
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
        'entities_id' => $entityId,
    ]);
    if (empty($links)) {
        $profileUser->add([
            'users_id' => $userId,
            'profiles_id' => $profileId,
            'entities_id' => $entityId,
            'is_recursive' => 1,
            'is_dynamic' => 0,
        ]);
    }
    return $userId;
}

function ensureEntity(string $name, int $parentId, array &$result): int
{
    $entity = new Entity();
    $found = $entity->find(['name' => $name, 'entities_id' => $parentId]);
    if ($found !== []) {
        $id = (int) array_key_first($found);
        $result['reused'][] = "entity:$id:$name";
        return $id;
    }

    $id = (int) $entity->add([
        'name' => $name,
        'entities_id' => $parentId,
        'comment' => 'Massa fictícia da homologação do plugin Gestão de Demandas.',
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Não foi possível criar a entidade $name.");
    }
    $result['created'][] = "entity:$id:$name";
    return $id;
}

function ensureGroup(string $name, array &$result): int
{
    $group = new Group();
    $found = $group->find(['name' => $name, 'entities_id' => 0]);
    if ($found !== []) {
        $id = (int) array_key_first($found);
        $result['reused'][] = "group:$id:$name";
        return $id;
    }

    $id = (int) $group->add([
        'name' => $name,
        'entities_id' => 0,
        'is_recursive' => 1,
    ]);
    if ($id <= 0) {
        throw new RuntimeException("Não foi possível criar o grupo $name.");
    }
    $result['created'][] = "group:$id:$name";
    return $id;
}

function ensureGroupMembership(int $userId, int $groupId): void
{
    $membership = new Group_User();
    if ($membership->find(['users_id' => $userId, 'groups_id' => $groupId]) === []) {
        $membership->add([
            'users_id' => $userId,
            'groups_id' => $groupId,
            'is_dynamic' => 0,
        ]);
    }
}

function ensureTicket(array $definition, int $entityId, int $requesterId, int $assigneeId, array &$result): int
{
    $ticket = new Ticket();
    $found = $ticket->find([
        'name' => (string) $definition['title'],
        'entities_id' => $entityId,
    ]);
    if ($found !== []) {
        $id = (int) array_key_first($found);
        $result['reused'][] = "ticket:$id";
        return $id;
    }

    $id = (int) $ticket->add([
        'name' => (string) $definition['title'],
        'content' => (string) $definition['content'],
        'entities_id' => $entityId,
        'status' => (int) $definition['status'],
        'type' => 2,
        'urgency' => (int) $definition['priority'],
        'impact' => (int) $definition['priority'],
        'priority' => (int) $definition['priority'],
        '_users_id_requester' => $requesterId,
        '_users_id_assign' => $assigneeId,
    ]);
    if ($id <= 0) {
        throw new RuntimeException('Não foi possível criar o chamado fictício: ' . $definition['title']);
    }
    $result['created'][] = "ticket:$id";
    return $id;
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
    $catalogPath = '/opt/demandas-bootstrap/glpi_homologation.json';
    $catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);

    // A entidade de ID zero é a raiz estrutural do GLPI e não pode ser recriada.
    $rootName = (string) $catalog['root_entity'];
    $escapedRootName = $DB->escape($rootName);
    $DB->doQuery("UPDATE glpi_entities SET name = '$escapedRootName' WHERE id = 0");

    $entityIds = [];
    foreach ($catalog['entities'] as $entityDefinition) {
        $clientId = ensureEntity((string) $entityDefinition['name'], 0, $result);
        $entityIds[(string) $entityDefinition['key']] = $clientId;
        foreach ($entityDefinition['units'] as $unitName) {
            ensureEntity((string) $unitName, $clientId, $result);
        }
    }

    $groupIds = [];
    foreach ($catalog['groups'] as $groupName) {
        $groupIds[(string) $groupName] = ensureGroup((string) $groupName, $result);
    }

    $requirementsProfileId = ensureProfile('Requisitos — Gestão de Demandas', 6, 'central', $result);
    $customerProfileId = ensureProfile('Cliente — Gestão de Demandas', 1, 'helpdesk', $result);
    $supportProfileId = ensureProfile('Analista de suporte — Homologação', 6, 'central', $result);
    $qaProfileId = ensureProfile('QA — Homologação', 6, 'central', $result);
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
        0,
        $result
    );
    $supportUserId = ensureUser(
        'suporte.hml',
        'Analista',
        'Suporte HML',
        'suporte.hml@example.invalid',
        $requirementsPassword,
        $supportProfileId,
        0,
        $result
    );
    $qaUserId = ensureUser(
        'qa.hml',
        'Analista',
        'QA HML',
        'qa.hml@example.invalid',
        $requirementsPassword,
        $qaProfileId,
        0,
        $result
    );
    ensureGroupMembership($requirementsUserId, $groupIds['TECNICOS INTERNOS']);
    ensureGroupMembership($supportUserId, $groupIds['ANALISTA SUPORTE']);
    ensureGroupMembership($qaUserId, $groupIds['QA']);

    $customerUsers = [];
    foreach ($catalog['entities'] as $entityDefinition) {
        $key = (string) $entityDefinition['key'];
        $customerUsers[$key] = ensureUser(
            'cliente.' . $key,
            'Cliente',
            (string) $entityDefinition['name'],
            'cliente.' . $key . '@example.invalid',
            $customerPassword,
            $customerProfileId,
            $entityIds[$key],
            $result
        );
        ensureGroupMembership($customerUsers[$key], $groupIds['SEDUC']);
    }

    // Mantém o usuário genérico usado por versões anteriores do pacote.
    $customerUserId = ensureUser(
        'cliente.mvp',
        'Cliente',
        'MVP',
        'cliente.mvp@example.invalid',
        $customerPassword,
        $customerProfileId,
        0,
        $result
    );

    $ticketIds = [];
    foreach ($catalog['tickets'] as $ticketDefinition) {
        $key = (string) $ticketDefinition['entity'];
        $ticketIds[] = ensureTicket(
            $ticketDefinition,
            $entityIds[$key],
            $customerUsers[$key],
            $requirementsUserId,
            $result
        );
    }
    $result['ticket_id'] = $ticketIds[0] ?? null;
    $result['ticket_ids'] = $ticketIds;
    $result['entity_ids'] = $entityIds;

    $result['requirements_profile_id'] = $requirementsProfileId;
    $result['customer_profile_id'] = $customerProfileId;
    $result['requirements_user_id'] = $requirementsUserId;
    $result['customer_user_id'] = $customerUserId;
    $result['support_user_id'] = $supportUserId;
    $result['qa_user_id'] = $qaUserId;
    echo 'MVP_RESULT=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'MVP_ERROR=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
