<?php
declare(strict_types=1);
// Synthetic fixtures only. Never run against shared or production databases.
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db'
    && !(getenv('GLPI_DB_HOST') === 'db' && getenv('GLPI_DB_NAME') === 'glpi_e2e')) {
    throw new RuntimeException('Use exclusivamente o banco isolado de testes.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production', false);
$kernel->boot();
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) require '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
});
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\Config as DC;
use GlpiPlugin\Demandas\LegacyReconciliationService as Reconciliation;
use GlpiPlugin\Demandas\TicketDemand;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if ($input['mode'] === 'seed') {
    $users = [];
    foreach (['admin', 'internal', 'client', 'readonly'] as $role) {
        $profile = new Profile();
        $found = $profile->find(['name' => 'Reconciliation QA ' . $role]);
        $pid = $found ? (int) array_key_first($found) : (int) $profile->add(['name' => 'Reconciliation QA ' . $role, 'interface' => $role === 'client' ? 'helpdesk' : 'central']);
        DP::initializeProfile($pid, []);
        foreach (DP::definitions() as $right => $_) DP::setRight($pid, $right, false);
        foreach ([DP::VIEW_PUBLIC, DP::VIEW_DASHBOARD, DP::EXPORT_DASHBOARD, DP::VIEW_TECHNICAL, DP::CREATE_WORK_PACKAGE] as $right) DP::setRight($pid, $right, $role !== 'client' || $right === DP::VIEW_PUBLIC);
        DP::setRight($pid, DP::MANAGE_RECONCILIATION_CONFLICTS, $role === 'admin');
        $right = new ProfileRight();
        $found = $right->find(['profiles_id' => $pid, 'name' => 'ticket']);
        $values = ['profiles_id' => $pid, 'name' => 'ticket', 'rights' => Ticket::READALL | READ | ($role === 'readonly' || $role === 'client' ? 0 : UPDATE)];
        if ($found) $right->update(['id' => (int) array_key_first($found)] + $values); else $right->add($values);
        $user = new User();
        $found = $user->find(['name' => 'reconciliation-' . $role]);
        $uid = $found ? (int) array_key_first($found) : (int) $user->add(['name' => 'reconciliation-' . $role, 'password' => $input['password'], 'password2' => $input['password'], 'is_active' => 1, 'authtype' => Auth::DB_GLPI, 'profiles_id' => $pid, 'entities_id' => 0]);
        $DB->update('glpi_users', ['password' => password_hash($input['password'], PASSWORD_DEFAULT)], ['id' => $uid]);
        $pu = new Profile_User();
        if (!$pu->find(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0])) $pu->add(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0, 'is_recursive' => 0]);
        DC::savePersonalToken($uid, 'synthetic-personal');
        $users[$role] = $uid;
    }
    Config::setConfigurationValues(DC::CONTEXT, ['openproject_internal_url' => 'http://127.0.0.1:8393/api/v3', 'openproject_external_url' => 'https://openproject.example.invalid', 'request_timeout' => 2]);
    // Both physical column conventions supported by historical Fields installations.
    $DB->doQuery('CREATE TABLE IF NOT EXISTS glpi_plugin_fields_containers (id int unsigned PRIMARY KEY, name varchar(255), itemtypes text)');
    $DB->doQuery('CREATE TABLE IF NOT EXISTS glpi_plugin_fields_fields (id int unsigned PRIMARY KEY, plugin_fields_containers_id int unsigned, name varchar(255), label varchar(255), type varchar(255))');
    foreach ([1 => ['reconciliationqa', 'devops'], 2 => ['reconciliationoldqa', 'plugin_fields_devops']] as $id => [$container, $column]) {
        $DB->doQuery("CREATE TABLE IF NOT EXISTS glpi_plugin_fields_ticket{$container}s (id int unsigned AUTO_INCREMENT PRIMARY KEY, items_id int unsigned, itemtype varchar(255), {$column} text)");
        $DB->updateOrInsert('glpi_plugin_fields_containers', ['name' => $container, 'itemtypes' => '["Ticket"]'], ['id' => 9000 + $id]);
        $DB->updateOrInsert('glpi_plugin_fields_fields', ['plugin_fields_containers_id' => 9000 + $id, 'name' => 'devops', 'label' => 'Atividade DevOps', 'type' => 'url'], ['id' => 9000 + $id]);
    }
    $tickets = [];
    foreach (['open' => 1, 'solved' => 5, 'closed' => 6, 'internal' => 2, 'conflict1' => 1, 'conflict2' => 1, 'transfer_source' => 1, 'transfer_target' => 1, 'invalid' => 1, 'foreign' => 1, 'unauthorized' => 1, 'forbidden' => 1, 'missing' => 1, 'invalid_api' => 1, 'timeout' => 1, 'other_entity' => 1] as $kind => $status) {
        $name = 'QA conciliação ' . $kind . ' <b>& teste</b>';
        $found = $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['name' => $name]])->current();
        if (!$found) {
            $DB->insert('glpi_tickets', ['name' => $name, 'content' => 'Conteúdo fictício.', 'entities_id' => $kind === 'other_entity' ? 99999 : 0, 'status' => $status, 'date' => '2026-09-01 12:00:00', 'date_mod' => '2026-09-01 12:00:00']);
            $tid = (int) $DB->insertId();
        } else $tid = (int) $found['id'];
        $tickets[$kind] = $tid;
    }
    $wps = ['open' => 91001, 'solved' => 91002, 'closed' => 91003, 'internal' => 91004, 'conflict1' => 91005, 'conflict2' => 91005, 'transfer_source' => 0, 'transfer_target' => 91008, 'invalid' => 0, 'foreign' => 91006, 'unauthorized' => 91401, 'forbidden' => 91403, 'missing' => 91404, 'invalid_api' => 91999, 'timeout' => 91504, 'other_entity' => 91007];
    foreach ($tickets as $kind => $tid) {
        $url = $kind === 'transfer_source' ? '' : ($kind === 'invalid' ? '91009' : ($kind === 'foreign' ? 'https://other.example.invalid' : 'https://openproject.example.invalid') . '/projects/qa/work_packages/' . $wps[$kind] . '/activity');
        $table = $kind === 'internal' ? 'glpi_plugin_fields_ticketreconciliationoldqas' : 'glpi_plugin_fields_ticketreconciliationqas';
        $col = $kind === 'internal' ? 'plugin_fields_devops' : 'devops';
        $DB->updateOrInsert($table, [$col => $url, 'itemtype' => 'Ticket'], ['items_id' => $tid]);
    }
    TicketDemand::saveLink($tickets['transfer_source'], 91008, 1, 'Projeto QA', 'qa', 1, 'User Story', 'Novo');
    $DB->update('glpi_plugin_demandas_links', ['public_phase' => '', 'public_message' => null], ['openproject_work_package_id' => 91008]);
    $DB->delete('glpi_plugin_demandas_events', ['openproject_work_package_id'=>91008,'event_type'=>'legacy_link_transferred','source'=>'legacy_reconciliation']);
    echo json_encode(['users' => $users, 'tickets' => $tickets, 'wps' => $wps]);
} elseif ($input['mode'] === 'reset-imports') {
    // Explicit reset of this suite's two imported fixtures only, in the guarded test DB.
    foreach ($DB->request(['FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'QA conciliação %']]]) as $ticket) {
        $where = ['tickets_id' => (int) $ticket['id'], 'openproject_work_package_id' => [91001, 91004]];
        $DB->delete('glpi_plugin_demandas_events', $where + ['source' => 'legacy_reconciliation']);
        $DB->delete('glpi_plugin_demandas_links', $where);
    }
    echo json_encode(['ok' => true]);
} elseif (in_array($input['mode'], ['upgrade', 'clean-install'], true)) {
    require_once '/var/www/glpi/plugins/demandas/setup.php';
    require_once '/var/www/glpi/plugins/demandas/hook.php';
    if ($input['mode'] === 'clean-install') {
        // Empty database explicitly created in the isolated container for this test.
        $target = 'glpi_reconciliation_clean_023';
        foreach ($DB->request(['FROM' => 'information_schema.TABLES', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault]]) as $row) {
            $table = $row['TABLE_NAME'];
            if (!preg_match('/^glpi_[a-z0-9_]+$/', $table) || str_starts_with($table, 'glpi_plugin_')) continue;
            $DB->doQuery("CREATE TABLE `{$target}`.`{$table}` LIKE `{$table}`");
            if (in_array($table, ['glpi_profiles', 'glpi_entities'], true)) $DB->doQuery("INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$table}`");
        }
        $DB->doQuery("USE `{$target}`");
        $DB->dbdefault = $target;
        if (!plugin_demandas_install() || !$DB->tableExists('glpi_plugin_demandas_links', false)) throw new RuntimeException('Instalação limpa falhou.');
    }
    $snapshot = static function () use ($DB): array {
        $data = [];
        foreach ($DB->request(['FROM' => 'information_schema.TABLES', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => ['LIKE', 'glpi_plugin_demandas_%']]]) as $table) {
            $name = $table['TABLE_NAME'];
            $data[$name] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM' => $name, 'ORDER' => 'id']))));
        }
        ksort($data); return $data;
    };
    $before = $snapshot();
    if (!plugin_demandas_install() || !plugin_demandas_install() || $before !== $snapshot()) throw new RuntimeException('Atualização alterou dados.');
    echo json_encode(['preserved_tables' => count($before), 'ok' => true]);
} elseif ($input['mode'] === 'webhook') {
    Config::setConfigurationValues(DC::CONTEXT, ['webhook_secret' => 'synthetic-reconciliation-signature']);
    $body = '{"action":"ignored-test-event"}';
    $handler = new GlpiPlugin\Demandas\WebhookHandler();
    $ok = $handler->handle($body, hash_hmac('sha256', $body, 'synthetic-reconciliation-signature'));
    $rejected = false;
    try { $handler->handle($body, 'invalid'); } catch (GlpiPlugin\Demandas\WebhookAuthenticationException) { $rejected = true; }
    if (!$rejected || $ok['processed']) throw new RuntimeException('Assinatura inválida aceita.');
    echo json_encode(['ok' => true]);
} elseif ($input['mode'] === 'protect-transfer') {
    // This sentinel touches only WP 91008 and its explicitly synthetic entry.
    $link = TicketDemand::findByWorkPackage(91008);
    if (!$link) throw new RuntimeException('Vínculo fictício ausente.');
    $DB->delete('glpi_plugin_demandas_time_entries', ['openproject_work_package_id'=>91008,'comment'=>'QA bloqueio transferência']);
    $DB->update('glpi_plugin_demandas_links', ['public_phase'=>$input['kind'] === 'public' ? 'Em análise' : '', 'public_message'=>null], ['id'=>(int)$link['id']]);
    if ($input['kind'] === 'time') {
        $DB->insert('glpi_plugin_demandas_time_entries', ['tickets_id'=>(int)$link['tickets_id'],'openproject_work_package_id'=>91008,'users_id'=>2,'spent_on'=>'2026-10-01','started_at'=>'09:00:00','ended_at'=>'10:00:00','minutes'=>60,'comment'=>'QA bloqueio transferência','created_by'=>2]);
    }
    echo json_encode(['ok'=>true]);
} elseif ($input['mode'] === 'time-seed') {
    foreach (['admin', 'internal', 'client'] as $role) {
        $profile = $DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Reconciliation QA ' . $role]])->current();
        foreach ([DP::VIEW_TIME_PORTAL, DP::LOG_OWN_TIME] as $right) DP::setRight((int) $profile['id'], $right, $role !== 'client');
    }
    Config::setConfigurationValues(DC::CONTEXT, ['openproject_internal_url' => 'http://127.0.0.1:8394/api/v3']);
    echo json_encode(['ok' => true]);
} elseif ($input['mode'] === 'time-snapshot') {
    $user = $DB->request(['FROM'=>'glpi_users', 'WHERE'=>['name'=>'reconciliation-admin']])->current();
    echo json_encode(array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_time_entries','WHERE'=>['users_id'=>(int)$user['id']],'ORDER'=>'id']))));
} elseif ($input['mode'] === 'snapshot') {
    $tables = ['glpi_plugin_demandas_links', 'glpi_plugin_demandas_events', 'glpi_itilfollowups'];
    $data = [];
    foreach ($tables as $table) $data[$table] = array_values(iterator_to_array($DB->request(['FROM' => $table, 'ORDER' => 'id'])));
    echo json_encode($data);
} elseif ($input['mode'] === 'parse') {
    $base = 'https://openproject.example.invalid';
    $cases = [
        [$base . '/work_packages/42', [42]],
        [$base . '/projects/demo/work_packages/43/activity?query_id=2', [43]],
        ['42', []], ['https://elsewhere.invalid/work_packages/42', []],
        ['https://openproject.example.invalid.evil.invalid/work_packages/42', []],
        ['https://person@openproject.example.invalid/work_packages/42', []],
        [$base . ':8443/work_packages/42', []], [$base . '/work_packages/42oops', []],
        [$base . '/work_packages/0', []], [$base . '/work_packages/42#activity', [42]],
    ];
    foreach ($cases as [$value, $expected]) if (Reconciliation::parseIds($value, $base) !== $expected) throw new RuntimeException('Parser: ' . $value);
    if (Reconciliation::parseIds($base . '/op/work_packages/45', $base . '/op') !== [45]) throw new RuntimeException('Subpath');
    echo json_encode(['cases' => count($cases) + 1]);
}
