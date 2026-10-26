<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use somente o banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
use GlpiPlugin\Demandas\Profile as DP;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$users=[]; $profiles=[]; $tickets=[];
if ($input['mode']==='seed') {
    $_SESSION['glpiactiveentities']=[0];
    $_SESSION['glpiactiveentities_string']='0';
    $entity = new Entity();
    $found = $DB->request(['FROM'=>'glpi_entities','WHERE'=>['name'=>'Security QA outside'],'ORDER'=>'id','LIMIT'=>1])->current();
    $outside = $found ? (int)$found['id'] : (int)$entity->add(['name'=>'Security QA outside','entities_id'=>0]);
    foreach (['admin','internal','viewer','client','noportal','foreign'] as $role) {
        $profile = new Profile(); $found=$profile->find(['name'=>'Security QA '.$role]);
        $pid=$found ? (int)array_key_first($found) : (int)$profile->add(['name'=>'Security QA '.$role,'interface'=>'central']);
        DP::initializeProfile($pid, []);
        foreach (DP::definitions() as $right=>$_) DP::setRight($pid,$right,false);
        $rights = $role==='admin' ? array_keys(DP::definitions()) : ($role==='client' ? [DP::VIEW_PUBLIC] : [DP::VIEW_TIME_PORTAL,DP::VIEW_OWN_ATTENDANCE,DP::LOG_OWN_TIME,DP::VIEW_TECHNICAL,DP::VIEW_OPERATIONAL_HEALTH]);
        if ($role==='internal') $rights[]=DP::MANAGE_OPERATIONAL_HEALTH;
        if ($role==='viewer') $rights[]=DP::VIEW_TEAM_ATTENDANCE;
        foreach($rights as $right) DP::setRight($pid,$right,true);
        if($role==='noportal') DP::setRight($pid,DP::VIEW_TIME_PORTAL,false);
        $DB->updateOrInsert('glpi_profilerights',['rights'=>READ|($role==='admin'?Ticket::READALL|UPDATE:0)],['profiles_id'=>$pid,'name'=>'ticket']);
        $DB->updateOrInsert('glpi_profilerights',['rights'=>$role==='admin'?READ|UPDATE:0],['profiles_id'=>$pid,'name'=>'user']);
        $user=new User();$found=$user->find(['name'=>'security-'.$role]);
        $uid=$found?(int)array_key_first($found):(int)$user->add(['name'=>'security-'.$role,'password'=>$input['password'],'password2'=>$input['password'],'is_active'=>1,'authtype'=>Auth::DB_GLPI,'profiles_id'=>$pid,'entities_id'=>$role==='foreign'?$outside:0]);
        $DB->update('glpi_users',['password'=>password_hash($input['password'],PASSWORD_DEFAULT)],['id'=>$uid]);
        // User::add may attach the default root profile; isolate each synthetic identity.
        $DB->delete('glpi_profiles_users',['users_id'=>$uid]);
        $pu=new Profile_User();
        if (!$pu->find(['users_id'=>$uid,'profiles_id'=>$pid,'entities_id'=>$role==='foreign'?$outside:0])) $pu->add(['users_id'=>$uid,'profiles_id'=>$pid,'entities_id'=>$role==='foreign'?$outside:0,'is_recursive'=>0]);
        $DB->update('glpi_users',['profiles_id'=>$pid,'entities_id'=>$role==='foreign'?$outside:0],['id'=>$uid]);
        $DB->delete('glpi_plugin_demandas_user_rights',['users_id'=>$uid]);
        $users[$role]=$uid;$profiles[$role]=$pid;
    }
    foreach (['visible','hidden','foreign','deleted'] as $kind) {
        $row=$DB->request(['FROM'=>'glpi_tickets','WHERE'=>['name'=>'Security QA '.$kind]])->current();
        if (!$row) {$DB->insert('glpi_tickets',['name'=>'Security QA '.$kind,'content'=>'Dados sintéticos','date'=>'2026-09-01 12:00:00','date_mod'=>'2026-09-01 12:00:00','entities_id'=>$kind==='foreign'?$outside:0,'status'=>1]);$id=(int)$DB->insertId();} else $id=(int)$row['id'];
        $DB->update('glpi_tickets',['is_deleted'=>$kind==='deleted'?1:0],['id'=>$id]);
        $DB->updateOrInsert('glpi_tickets_users',['use_notification'=>0],['tickets_id'=>$id,'users_id'=>$kind==='visible'?$users['internal']:$users['admin'],'type'=>1]);
        $tickets[$kind]=$id;
        // No matching rule: readable tickets should resolve; hidden ones must remain untouched.
        $DB->updateOrInsert('glpi_plugin_demandas_operational_findings',[
            'rule_code'=>'ticket_without_wp','severity'=>'high','tickets_id'=>$id,'entities_id'=>$kind==='foreign'?$outside:0,
            'state'=>$kind==='hidden'?'acknowledged':($kind==='foreign'?'ignored':'open'),
            'ignored_until'=>'2030-01-01 00:00:00','evidence_json'=>'{}','first_detected_at'=>'2026-09-01 12:00:00','last_detected_at'=>'2026-09-01 12:00:00','date_creation'=>'2026-09-01 12:00:00','date_mod'=>'2026-09-01 12:00:00',
        ],['fingerprint'=>hash('sha256','ticket_without_wp|'.$id.'|0')]);
    }
    Config::setConfigurationValues('plugin:demandas',['classification_source_json'=>'{"mode":"none"}']);
    file_put_contents('/tmp/security-stage1-fixture.json',json_encode(compact('users','profiles','tickets','outside')));
    echo json_encode(compact('users','profiles','tickets','outside'));
} else {
    $seed=json_decode(file_get_contents('/tmp/security-stage1-fixture.json'),true);
    if($input['mode']==='snapshot') {
        $result=[];
        foreach(['user_time_settings','user_rights','punches','absences','time_audit'] as $name) $result[$name]=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_'.$name,'WHERE'=>[$name==='time_audit'?'target_users_id':'users_id'=>array_values($seed['users'])],'ORDER'=>'id'])));
        $result['findings']=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_operational_findings','WHERE'=>['tickets_id'=>array_values($seed['tickets'])],'ORDER'=>'id'])));
        echo json_encode($result);
    } elseif($input['mode']==='classification-failure') {
        $DB->update('glpi_plugin_demandas_operational_findings',['state'=>'open'],['tickets_id'=>$seed['tickets']['visible']]);
        Config::setConfigurationValues('plugin:demandas',['classification_source_json'=>'{"mode":"fields_plugin","field_id":99999999}']);echo '{}';
    } elseif($input['mode']==='classification-eligible') {
        Config::setConfigurationValues('plugin:demandas',['classification_source_json'=>'{"mode":"ticket_field","field":"type"}','classification_rules_json'=>'[{"value":"1","allowed_type_names":["Bug"]}]']);echo '{}';
    } elseif($input['mode']==='right') {
        DP::setRight($seed['profiles'][$input['role']],$input['right'],(bool)$input['enabled']);echo '{}';
    } elseif($input['mode']==='legacy-exception') {
        $DB->updateOrInsert('glpi_plugin_demandas_user_rights',['decision'=>1,'date_mod'=>'2026-09-01 12:00:00'],['users_id'=>$seed['users']['internal'],'right_name'=>DP::MANAGE_CONFIG]);echo '{}';
    }
}
