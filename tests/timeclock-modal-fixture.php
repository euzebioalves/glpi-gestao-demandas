<?php
declare(strict_types=1);
// Synthetic users only, in the dedicated disposable Docker database.
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use o banco isolado demandas-test-021-db.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) require '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
});
use GlpiPlugin\Demandas\Profile as DP;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$users = [];
foreach (['admin', 'internal', 'client'] as $role) {
    $profile = new Profile();
    $found = $profile->find(['name' => 'Modal QA ' . $role]);
    $pid = $found ? (int) array_key_first($found) : (int) $profile->add(['name' => 'Modal QA ' . $role, 'interface' => $role === 'client' ? 'helpdesk' : 'central']);
    DP::initializeProfile($pid, []);
    foreach (DP::definitions() as $right => $_) DP::setRight($pid, $right, false);
    foreach ([DP::VIEW_TIME_PORTAL, DP::VIEW_OWN_ATTENDANCE] as $right) DP::setRight($pid, $right, $role !== 'client');
    DP::setRight($pid, DP::MANAGE_ATTENDANCE, $role === 'admin');
    $login = 'modal-qa-' . $role . '-' . bin2hex(random_bytes(4));
    $user = new User();
    $uid = (int) $user->add(['name' => $login, 'password' => $input['password'], 'password2' => $input['password'], 'is_active' => 1, 'authtype' => Auth::DB_GLPI, 'profiles_id' => $pid, 'entities_id' => 0]);
    if ($uid <= 0) throw new RuntimeException('Falha ao criar usuário fictício.');
    $pu = new Profile_User();
    if (!$pu->find(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0])) $pu->add(['users_id' => $uid, 'profiles_id' => $pid, 'entities_id' => 0, 'is_recursive' => 0]);
    $DB->insert('glpi_plugin_demandas_punches', ['users_id' => $uid, 'punch_at' => '2026-10-07 07:00:00', 'punch_type' => 'entrada_manha', 'nsr' => 'QA-1', 'note' => 'Registro fictício', 'created_by' => $uid]);
    $users[$role] = ['id' => $uid, 'login' => $login];
}
echo json_encode($users);
