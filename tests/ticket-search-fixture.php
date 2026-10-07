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
    $found = $profile->find(['name' => 'Search WP QA ' . $role]);
    $pid = $found ? (int) array_key_first($found) : (int) $profile->add(['name' => 'Search WP QA ' . $role, 'interface' => 'central']);
    DP::initializeProfile($pid, []);
    foreach (DP::definitions() as $right => $_) DP::setRight($pid, $right, false);
    DP::setRight($pid, DP::VIEW_PUBLIC, true);
    DP::setRight($pid, DP::VIEW_TECHNICAL, $role !== 'client');
    $DB->updateOrInsert('glpi_profilerights', ['rights' => Ticket::READALL | READ], ['profiles_id'=>$pid, 'name'=>'ticket']);
    $DB->updateOrInsert('glpi_profilerights', ['rights' => DisplayPreference::PERSONAL], ['profiles_id'=>$pid, 'name'=>'search_config']);
    $login = 'search-wp-qa-' . $role . '-' . bin2hex(random_bytes(4));
    $user = new User();
    $uid = (int) $user->add(['name'=>$login, 'password'=>$input['password'], 'password2'=>$input['password'], 'is_active'=>1, 'authtype'=>Auth::DB_GLPI, 'profiles_id'=>$pid, 'entities_id'=>0]);
    if ($uid <= 0) throw new RuntimeException('Falha ao criar usuário fictício.');
    $pu = new Profile_User();
    if (!$pu->find(['users_id'=>$uid, 'profiles_id'=>$pid, 'entities_id'=>0])) $pu->add(['users_id'=>$uid, 'profiles_id'=>$pid, 'entities_id'=>0, 'is_recursive'=>0]);
    $users[$role] = ['id'=>$uid, 'login'=>$login];
}
Config::setConfigurationValues('plugin:demandas', ['openproject_external_url'=>'https://openproject.example.invalid']);
$_SESSION['glpiactiveentities_string']='0';
$entity=new Entity();
$found=$entity->find(['name'=>'Search WP QA other entity']);
$otherEntity=$found ? (int)array_key_first($found) : (int)$entity->add(['name'=>'Search WP QA other entity','entities_id'=>0]);
$tickets=[];
foreach (['none','single','multiple','legacy','foreign'] as $kind) {
    $name='QA Search WP ' . $kind;
    $found=$DB->request(['FROM'=>'glpi_tickets','WHERE'=>['name'=>$name]])->current();
    if (!$found) {
        $DB->insert('glpi_tickets', ['name'=>$name,'content'=>'Chamado fictício.','status'=>1,'entities_id'=>$kind === 'foreign' ? $otherEntity : 0, 'date'=>'2026-10-07 09:00:00','date_mod'=>'2026-10-07 09:00:00']);
        $tickets[$kind]=(int)$DB->insertId();
    } else $tickets[$kind]=(int)$found['id'];
    $DB->update('glpi_tickets',['entities_id'=>$kind === 'foreign' ? $otherEntity : 0],['id'=>$tickets[$kind]]);
}
foreach (['single'=>[982101], 'multiple'=>[982102,982103], 'foreign'=>[982104]] as $kind=>$ids) {
    foreach ($ids as $wp) GlpiPlugin\Demandas\TicketDemand::saveLink($tickets[$kind],$wp,1,'Projeto QA','qa',1,'User Story','Novo');
}
// A legacy Fields value alone is deliberately not a plugin link.
$DB->doQuery('CREATE TABLE IF NOT EXISTS glpi_plugin_fields_ticketsearchqas (id int unsigned AUTO_INCREMENT PRIMARY KEY, items_id int unsigned, itemtype varchar(255), devops text)');
$DB->updateOrInsert('glpi_plugin_fields_ticketsearchqas',['devops'=>'https://openproject.example.invalid/work_packages/982105'],['items_id'=>$tickets['legacy'],'itemtype'=>'Ticket']);
echo json_encode(['users'=>$users,'tickets'=>$tickets]);
