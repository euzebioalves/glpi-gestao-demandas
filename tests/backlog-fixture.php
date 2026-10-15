<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use o banco isolado.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
spl_autoload_register(static function ($class) {
    $prefix='GlpiPlugin\\Demandas\\';
    if (str_starts_with($class,$prefix)) require '/var/www/glpi/plugins/demandas/src/'.substr($class,strlen($prefix)).'.php';
});
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\Config as DC;
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if (isset($input['token'])) { DC::savePersonalToken((int)$input['user'],$input['token']); echo '{}'; return; }
$users=[];
foreach (['admin','internal','readonly','client'] as $role) {
    $profile=new Profile();
    $pid=(int)$profile->add(['name'=>'Backlog QA '.$role.' '.bin2hex(random_bytes(3)),'interface'=>'central']);
    DP::initializeProfile($pid,[]);
    DP::setRight($pid,DP::VIEW_TECHNICAL,$role!=='client');
    DP::setRight($pid,DP::VIEW_BACKLOG,true);
    DP::setRight($pid,DP::CONFIGURE_BACKLOG,in_array($role,['admin','internal'],true));
    DP::setRight($pid,DP::MANAGE_CONFIG,$role==='admin');
    $login='backlog-qa-'.$role.'-'.bin2hex(random_bytes(4));
    $user=new User();
    $uid=(int)$user->add(['name'=>$login,'password'=>$input['password'],'password2'=>$input['password'],'is_active'=>1,'authtype'=>Auth::DB_GLPI,'profiles_id'=>$pid,'entities_id'=>0]);
    if (!$uid) throw new RuntimeException('Falha no usuário de teste.');
    $pu=new Profile_User();
    if (!$pu->find(['users_id'=>$uid,'profiles_id'=>$pid,'entities_id'=>0])) $pu->add(['users_id'=>$uid,'profiles_id'=>$pid,'entities_id'=>0,'is_recursive'=>0]);
    DC::savePersonalToken($uid,'qa-personal');
    $users[$role]=['id'=>$uid,'login'=>$login];
}
Config::setConfigurationValues(DC::CONTEXT,['openproject_internal_url'=>'http://127.0.0.1:8396/api/v3','openproject_external_url'=>'https://openproject.example.invalid','request_timeout'=>2]);
echo json_encode(['users'=>$users]);
