<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
use GlpiPlugin\Demandas\{Profile, OperationalHealthService as Health, IndicatorPolicy as Policy, ActionDashboard as Dashboard};
global $DB;
$checks=0;
function check(bool $value, string $label): void { global $checks; $checks++; if (!$value) throw new RuntimeException($label); }
function rejected(callable $call, string $label): void { try {$call();} catch (Throwable) {check(true,$label);return;} check(false,$label); }
$DB->beginTransaction();
try {
    $uid=(int)$DB->request(['FROM'=>'glpi_users','WHERE'=>['name'=>'security-admin']])->current()['id'];
    $DB->insert('glpi_entities',['name'=>'QA035 isolated','completename'=>'QA035 isolated','entities_id'=>0]); $entity=(int)$DB->insertId();
    $_SESSION['glpiID']=$uid; $_SESSION['glpiactiveentities']=[$entity]; $_SESSION['glpiactiveentities_string']=(string)$entity;
    $profile=$DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Security QA admin']])->current();
    $DB->insert('glpi_profiles_users',['users_id'=>$uid,'profiles_id'=>$profile['id'],'entities_id'=>$entity,'is_recursive'=>0]);
    $_SESSION['valid_id']=session_id(); $_SESSION['glpiactive_entity']=$entity;
    $_SESSION['glpiactiveprofile']=$profile+['ticket'=>READ|UPDATE|Ticket::READALL];
    foreach(Profile::definitions() as $key=>$_) $_SESSION['glpiactiveprofile'][$key]=READ;
    Config::setConfigurationValues('plugin:demandas',['classification_source_json'=>'{"mode":"none"}','operational_health_initial_days'=>'7','operational_health_stale_days'=>'7','operational_health_initial_statuses'=>'Novo,Em especificação']);
    // One reference instant: a slow DB must not place a later fixture event after its snapshot.
    $referenceTime=time()-60;
    $date=static fn(int $days)=>date('Y-m-d H:i:s',$referenceTime-$days*86400);
    check(Policy::freshness(null)==='not_consulted','No sync is not zero');
    check(Policy::freshness('2026-02-30 10:00:00')==='invalid_date','Impossible date');
    check(Policy::freshness($date(-2))==='invalid_date','Future date');
    check(Policy::freshness($date(8))==='stale','Stale');
    check(Policy::freshness($date(0))==='current','Current');
    check(!Policy::delivered('Desenvolvido') && Policy::delivered(' Entregue '),'Delivery unchanged');
    check(Policy::initial(' EM ESPECIFICAÇÃO ',['Novo','Em especificação']),'Configured initial statuses');
    $links='glpi_plugin_demandas_links';$events='glpi_plugin_demandas_events';$findings='glpi_plugin_demandas_operational_findings';$actions='glpi_plugin_demandas_operational_actions';
    $tickets=[];
    foreach (['recent','old','unknown','closed','stale'] as $kind) {
        $DB->insert('glpi_tickets',['name'=>'QA035 '.$kind,'entities_id'=>$entity,'status'=>1,'date'=>$date(100),'date_mod'=>$date(0)]);
        $id=(int)$DB->insertId();$tickets[$kind]=$id;
        $wp=$id+900000;
        $DB->insert($links,['tickets_id'=>$id,'openproject_work_package_id'=>$wp,'openproject_status'=>$kind==='closed'?'Entregue':'Novo','date_creation'=>$date(100),'last_synced_at'=>$kind==='stale'?$date(10):$date(0)]);
        if ($kind!=='unknown') $DB->insert($events,['tickets_id'=>$id,'openproject_work_package_id'=>$wp,'event_type'=>'work_package_synchronized','is_success'=>1,'new_status'=>$kind==='closed'?'Entregue':'Novo','date_creation'=>$date($kind==='recent'?1:20)]);
    }
    // Prior occurrence with no current matching rule must survive a partial round.
    $DB->insert($findings,['fingerprint'=>hash('sha256','ticket_without_wp|'.$tickets['closed'].'|0'),'rule_code'=>'ticket_without_wp','severity'=>'high','tickets_id'=>$tickets['closed'],'entities_id'=>$entity,'state'=>'acknowledged','evidence_json'=>'{"reason":"old evidence"}']);
    $prior=(int)$DB->insertId();
    $service=new Health(); $run=$service->run($uid);
    check($run['status']==='partial' && $run['incomplete_links']===2,'Missing history and stale make round partial');
    check($DB->request(['FROM'=>$findings,'WHERE'=>['id'=>$prior]])->current()['state']==='acknowledged','Partial round preserves prior finding');
    $rows=$service->findings();
    check(!array_filter($rows,fn($r)=>(int)$r['tickets_id']===$tickets['recent'] && $r['rule_code']==='wp_initial_status_overdue'),'Old link recently returned to initial is not overdue');
    $old=array_values(array_filter($rows,fn($r)=>(int)$r['tickets_id']===$tickets['old'] && $r['rule_code']==='wp_initial_status_overdue'))[0];
    check($old['evidence']['age_days']===20,'Uses observed history, not 100-day link age');
    $unknown=array_values(array_filter($rows,fn($r)=>(int)$r['tickets_id']===$tickets['unknown']))[0];
    check($unknown['rule_code']==='wp_data_quality' && $unknown['evidence']['age_days']===null,'Unknown stays unknown');
    $service->act((int)$old['id'],'followup',$uid,['due_date'=>date('Y-m-d',strtotime('+2 days')),'note'=>'Conferência sintética','take_ownership'=>'1','owner_users_id'=>'99999']);
    check((int)$service->detail((int)$old['id'])['owner_users_id']===$uid,'Cannot assign arbitrary owner');
    rejected(fn()=>$service->act((int)$old['id'],'ignore',$uid),'Reason required for deferral');
    rejected(fn()=>$service->act((int)$old['id'],'followup',$uid,['due_date'=>'2026-02-30']),'Invalid due date rejected');
    $service->act((int)$old['id'],'ignore',$uid,['note'=>'Aguardar conferência com equipe.']);
    $service->run($uid);
    check($service->detail((int)$old['id'])['state']==='ignored','Repeated run preserves deferral');
    check(count(array_filter($service->findings(),fn($r)=>(int)$r['tickets_id']===$tickets['old'] && $r['rule_code']==='wp_initial_status_overdue'))===1,'No duplicate finding');
    $history=$service->history((int)$old['id']);
    check(count($history)===2 && json_decode($history[0]['details_json'],true)['note']==='Aguardar conferência com equipe.','Audited treatment');
    // Complete evidence now permits resolution, with preserved previous evidence.
    $DB->insert($events,['tickets_id'=>$tickets['unknown'],'openproject_work_package_id'=>$tickets['unknown']+900000,'event_type'=>'work_package_synchronized','is_success'=>1,'new_status'=>'Novo','date_creation'=>$date(0)]);
    $DB->update($links,['last_synced_at'=>$date(0)],['tickets_id'=>$tickets['stale']]);
    $run=$service->run($uid);check($run['status']==='success','Complete round');
    check($service->detail($prior)['state']==='resolved','Complete round resolves absent rule');
    check(!array_filter($service->findings(['state'=>'active']),fn($row)=>$row['state']==='resolved'),'Active filter excludes resolved findings');
    $resolution=$service->history($prior)[0];check($resolution['action']==='resolved' && json_decode($resolution['details_json'],true)['run_id']===$run['run_id'],'Resolution linked to audited round');
    foreach(['access_denied','unavailable','sync_failed'] as $category) {
        $DB->insert($events,['tickets_id'=>$tickets['old'],'openproject_work_package_id'=>$tickets['old']+900000,'event_type'=>'work_package_synchronized','is_success'=>0,'details_json'=>json_encode(['failure_category'=>$category]),'date_creation'=>date('Y-m-d H:i:s')]);
        check($service->run($uid)['status']==='partial','Failed latest attempt makes partial');
        $quality=array_values(array_filter($service->findings(),fn($r)=>(int)$r['tickets_id']===$tickets['old'] && $r['rule_code']==='wp_data_quality'))[0];
        check($quality['evidence']['quality']===$category,'Structured failure category');
    }
    $response=new GuzzleHttp\Psr7\Response(403);$request=new GuzzleHttp\Psr7\Request('GET','https://example.invalid');
    check(Policy::failureCategory(new RuntimeException('wrapper',0,new GuzzleHttp\Exception\RequestException('private',$request,$response)))==='access_denied','Wrapped HTTP failure classified without text');
    // Simulate failure in final DB write: all candidate changes must roll back.
    $snapshot=static fn()=>json_encode(iterator_to_array($DB->request(['FROM'=>$findings,'WHERE'=>['entities_id'=>$entity],'ORDER'=>'id'])));
    $before=$snapshot();
    $proxy=new class($DB) {
        public function __construct(private object $db) {}
        public function __call($method,$args) {
            if ($method==='update' && $args[0]==='glpi_plugin_demandas_operational_runs' && in_array($args[1]['status']??'', ['success','partial'], true)) return false;
            if ($method==='insert' && $args[0]==='glpi_plugin_demandas_operational_actions') return false;
            return $this->db->$method(...$args);
        }
    };
    $failing=new Health();(new ReflectionProperty($failing,'db'))->setValue($failing,$proxy);
    rejected(fn()=>$failing->run($uid),'Final run write fails');check($snapshot()===$before,'Failed run rolls back findings');
    rejected(fn()=>$failing->act((int)$old['id'],'reopen',$uid),'Audit write fails');check($snapshot()===$before,'Failed treatment audit rolls back state');
    $_SESSION['glpiactiveentities']=[0];
    rejected(fn()=>$service->detail((int)$old['id']),'Foreign detail denied');
    rejected(fn()=>$service->history((int)$old['id']),'Foreign history denied');
    check($service->latestActions([(int)$old['id']])===[],'Foreign latest audit hidden');
    echo "PASS: $checks indicator/treatment/rollback assertions. All fixture data rolled back.\n";
} finally { $DB->rollBack(); }
