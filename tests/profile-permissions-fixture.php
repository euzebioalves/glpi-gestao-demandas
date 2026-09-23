<?php
declare(strict_types=1);
// Somente fixtures sintéticas do ambiente descartável de testes da release.
if (!in_array(getenv('GLPI_DB_HOST'), ['demandas-release-0183-db', 'demandas-release-0184-db'], true)) {
    throw new RuntimeException('Use somente o banco Docker isolado da release.');
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
use GlpiPlugin\Demandas\Config as DC;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$mode = $input['mode'];
if ($mode === 'baseline') {
    $_SESSION['glpiactiveprofile'] = ['name'=>'Master', DP::MANAGE_CONFIG=>READ];
    if (!DP::has(DP::MANAGE_CONFIG) || DC::isActiveSuperAdmin()) throw new RuntimeException('Baseline inesperada');
    echo json_encode(['reproduced'=>true]);
} elseif ($mode === 'seed') {
    $ids=[];
    foreach (['Master', 'Administrador Corporativo', 'Comum Release', 'Cliente Release'] as $name) {
        $profile = new Profile();
        $existing=$profile->find(['name'=>$name]);
        $id=$existing ? (int)array_key_first($existing) : (int)$profile->add(['name'=>$name,'interface'=>$name==='Cliente Release'?'helpdesk':'central']);
        DP::initializeProfile($id, []);
        foreach (DP::definitions() as $right=>$_label) DP::setRight($id, $right, false);
        DP::setRight($id, DP::MANAGE_CONFIG, in_array($name, ['Master','Administrador Corporativo'], true));
        DP::setRight($id, DP::VIEW_TIME_PORTAL, true);
        DP::setRight($id, DP::VIEW_OWN_ATTENDANCE, true);
        $ids[$name]=$id;
    }
    $super=$DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Super-Admin']])->current();
    $ids['Super-Admin']=(int)$super['id'];
    DP::setRight($ids['Super-Admin'], DP::MANAGE_CONFIG, false);
    $user = new User();
    $found=$user->find(['name'=>'release-permissions']);
    $uid=$found ? (int)array_key_first($found) : (int)$user->add(['name'=>'release-permissions','password'=>$input['password'],'password2'=>$input['password'],'is_active'=>1,'authtype'=>Auth::DB_GLPI,'profiles_id'=>$ids['Master'],'entities_id'=>0]);
    foreach ($ids as $id) {
        $pu=new Profile_User();
        if (!$pu->find(['users_id'=>$uid,'profiles_id'=>$id,'entities_id'=>0])) $pu->add(['users_id'=>$uid,'profiles_id'=>$id,'entities_id'=>0,'is_recursive'=>1]);
    }
    Config::setConfigurationValues(DC::CONTEXT, ['openproject_internal_url'=>'http://127.0.0.1:8390/api/v3','openproject_automation_api_token'=>'fixture-automation','webhook_secret'=>'fixture-webhook','request_timeout'=>'2']);
    DC::savePersonalToken($uid, 'fixture-personal');
    echo json_encode(['profiles'=>$ids,'user'=>$uid]);
} elseif ($mode === 'snapshot') {
    $data=[];
    foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $row) {
        $table=$row['TABLE_NAME'];
        $data[$table]=array_values(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id'])));
    }
    $data['config']=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_configs','WHERE'=>['context'=>DC::CONTEXT],'ORDER'=>'id'])));
    $data['rights']=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_profilerights','ORDER'=>'id'])));
    ksort($data);
    echo json_encode(array_map(static fn($rows)=>hash('sha256',json_encode($rows)), $data));
} elseif ($mode === 'right') {
    DP::setRight((int)$input['profile'], $input['right']??DP::MANAGE_CONFIG, (bool)$input['enabled']);
    echo json_encode(['ok'=>true]);
} elseif ($mode === 'assert-token') {
    echo json_encode(['matches'=>DC::personalToken((int)$input['user'])===$input['token'],'automationPreserved'=>DC::automationToken()==='fixture-automation']);
} elseif ($mode === 'helper') {
    foreach (['Master','Administrador Corporativo','Super-Admin','Qualquer nome',''] as $name) {
        foreach ([false,true] as $enabled) {
            $_SESSION['glpiactiveprofile']=['name'=>$name, DP::MANAGE_CONFIG=>$enabled ? READ : 0];
            if (DC::canManageConfiguration() !== $enabled) throw new RuntimeException('Falha na permissão efetiva');
            if (DP::has(DP::CREATE_WORK_PACKAGE)) throw new RuntimeException('Direitos acoplados');
        }
    }
    echo json_encode(['passed'=>10]);
}
