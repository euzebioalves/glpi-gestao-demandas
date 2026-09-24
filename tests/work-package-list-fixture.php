<?php
declare(strict_types=1);

// Synthetic data only; never run this fixture against a shared database.
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') {
    throw new RuntimeException('Use o banco Docker isolado demandas-test-021-db.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production', false);
$kernel->boot();
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) {
        require_once '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
    }
});
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\WorkPackageMonitoringList as Listing;
use GlpiPlugin\Demandas\WorkPackageMonitoringService as Service;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if ($input['mode'] === 'seed') {
    $users = [];
    foreach (['admin', 'internal', 'client'] as $role) {
        $profile = new Profile();
        $found = $profile->find(['name' => 'WP Test ' . $role]);
        $pid = $found ? (int) array_key_first($found) : (int) $profile->add(['name' => 'WP Test ' . $role, 'interface' => $role === 'client' ? 'helpdesk' : 'central']);
        DP::initializeProfile($pid, []);
        DP::setRight($pid, DP::VIEW_DASHBOARD, $role !== 'client');
        DP::setRight($pid, DP::EXPORT_DASHBOARD, $role === 'admin');
        $user = new User();
        $found = $user->find(['name' => 'wp-test-' . $role]);
        $uid = $found ? (int) array_key_first($found) : (int) $user->add(['name' => 'wp-test-' . $role, 'password' => $input['password'], 'password2' => $input['password'], 'is_active' => 1, 'authtype' => Auth::DB_GLPI, 'profiles_id' => $pid, 'entities_id' => 0]);
        if ($uid <= 0) {
            throw new RuntimeException('Não foi possível criar o usuário fictício.');
        }
        $DB->update('glpi_users', ['password' => password_hash($input['password'], PASSWORD_DEFAULT)], ['id' => $uid]);
        $link = new Profile_User();
        if (!$link->find(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0])) {
            $link->add(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0, 'is_recursive' => 1]);
        }
        $users[$role] = $uid;
        for ($i = 1; $i <= ($role === 'client' ? 0 : 61); $i++) {
            $wp = ($role === 'admin' ? 1000 : 2000) + $i;
            if (count($DB->request(['FROM' => 'glpi_plugin_demandas_work_package_monitors', 'WHERE' => ['users_id' => $uid, 'openproject_work_package_id' => $wp]])) > 0) {
                continue;
            }
            $details = ['id' => $wp, 'subject' => $i === 1 ? '=1+1 <b>ação & revisão</b>' : 'WP fictícia ' . $wp, 'type' => 'User Story', 'status' => $i <= 31 ? 'Novo' : 'Em execução', 'responsible' => 'Equipe ' . $role, 'customer' => 'Cliente fictício & QA', 'created_at' => '2026-09-01T10:00:00Z', 'updated_at' => '2026-09-24T10:00:00Z', 'openproject_url' => 'https://example.invalid/work_packages/' . $wp];
            $DB->insert('glpi_plugin_demandas_work_package_monitors', ['users_id' => $uid, 'openproject_work_package_id' => $wp, 'responsible_name' => $details['responsible'], 'status_name' => $details['status'], 'is_open' => 1, 'details_json' => json_encode($details), 'ticket_ids_json' => '[]', 'last_queried_at' => '2026-09-24 10:00:00', 'date_creation' => '2026-09-24 10:00:00', 'date_mod' => '2026-09-24 10:00:00']);
        }
    }
    echo json_encode($users);
} elseif ($input['mode'] === 'snapshot') {
    $snapshot = [];
    foreach ($DB->request(['FROM' => 'information_schema.TABLES', 'WHERE' => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => ['LIKE', 'glpi_plugin_demandas_%']]]) as $table) {
        $name = $table['TABLE_NAME'];
        $snapshot[$name] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM' => $name, 'ORDER' => 'id']))));
    }
    ksort($snapshot);
    echo json_encode($snapshot);
} elseif ($input['mode'] === 'unit') {
    $check = static function (bool $ok): void { if (!$ok) throw new RuntimeException('Falha na paginação.'); };
    $rows = range(1, 61);
    $check(Listing::paginate($rows, ['page' => 3])['rows'] === range(51, 61));
    $check(Listing::paginate($rows, ['page' => 999])['page'] === 3);
    $check(Listing::paginate($rows, ['page' => -2])['page'] === 1);
    $check(Listing::paginate([], ['page' => 999])['first'] === 0);
    $check(Listing::pageSize(['per_page' => 999999]) === 25);
    $check(Listing::pageSize(['per_page' => [50]]) === 25);
    $check(Listing::paginate($rows, ['page' => [2]])['page'] === 1);
    $check(Listing::filters(['status' => ['Novo']])['status'] === '');
    foreach (Listing::PAGE_SIZES as $size) {
        $check(count(Listing::paginate($rows, ['per_page' => $size])['rows']) === min(61, $size));
    }
    echo json_encode(['ok' => true]);
}
