<?php
declare(strict_types=1);
if(getenv('GLPI_DB_HOST')!=='demandas-test-021-db')throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production',false))->boot();
use GlpiPlugin\Demandas\TimeManagementService as Times;
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\SynchronizationService;
global $DB;
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$user=$DB->request(['FROM'=>'glpi_users','WHERE'=>['name'=>($in['user']??'')==='security-admin'?'security-admin':'reconciliation-admin']])->current();
$uid=(int)$user['id'];
$_SESSION['glpiID']=$uid;$_SESSION['glpiactiveentities']=[0];$_SESSION['glpiactiveentities_string']='0';
$_SESSION['glpiactiveprofile']=['interface'=>'central','ticket'=>READ|UPDATE|Ticket::READALL,'followup'=>READ|CREATE|UPDATE];
foreach(DP::definitions()as$key=>$_)$_SESSION['glpiactiveprofile'][$key]=READ;
$service=new Times();
$table='glpi_plugin_demandas_time_entries';
$base=['users_id'=>$uid,'tickets_id'=>0,'work_package_id'=>98001,'spent_on'=>'2026-10-20','started_at'=>'09:00','ended_at'=>'10:00','activity_href'=>'/api/v3/time_entries/activities/1','activity_name'=>'QA','comment'=>'Operação sintética'];
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
try{
    switch($in['mode']){
        case 'configure':
            GlpiPlugin\Demandas\Config::savePersonalToken($uid,'synthetic-personal');
            Config::setConfigurationValues('plugin:demandas',['openproject_internal_url'=>'http://127.0.0.1:8398/api/v3','openproject_automation_api_token'=>'synthetic-personal','request_timeout'=>'2','webhook_secret'=>'synthetic-stage2-webhook','status_rules_json'=>json_encode(['1'=>['auto_followup'=>1,'private_followup'=>0,'message'=>'QA transição {{status.previous}} para {{status.current}}']])]);
            echo '{}';break;
        case 'local':
            $date='2026-10-20';
            $service->saveDay($uid,$date,[['time'=>'08:00'],['time'=>'17:00']],'set',['absence_kind'=>'justified']);
            $snapshot=static function()use($DB,$uid){$out=[];foreach(['punches','absences','absence_files','time_audit']as$t)$out[$t]=iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_'.$t,'WHERE'=>[$t==='time_audit'?'target_users_id':'users_id'=>$uid],'ORDER'=>'id']));return json_encode($out);};
            $before=$snapshot();
            foreach([
                [[['time'=>'09:00'],['time'=>'bad']],'keep',[],[]],
                [[['time'=>'09:00'],['time'=>'09:00']],'keep',[],[]],
                [[['time'=>'09:00'],['time'=>'16:00']],'set',['absence_scope'=>'partial','absence_start'=>'16:00','absence_end'=>'15:00'],[]],
                [[['time'=>'09:00'],['time'=>'16:00']],'set',['absence_kind'=>'justified'],[['name'=>'bad.exe','error'=>0,'size'=>8]]],
            ]as[$records,$action,$input,$files]){
                $failed=false;try{$service->saveDay($uid,$date,$records,$action,$input,$files);}catch(Throwable){$failed=true;}
                check($failed&&$before===$snapshot(),'Falha parcial alterou ponto, ausência ou auditoria');
            }
            $service->saveDay($uid,$date,[['time'=>'08:00'],['time'=>'12:00'],['time'=>'13:00'],['time'=>'17:00']],'remove',[]);
            $service->saveDay($uid,$date,[['time'=>'08:00'],['time'=>'12:00'],['time'=>'13:00'],['time'=>'17:00']],'remove',[]);
            check(count($service->month($uid,2026,10)[$date]['punches'])===4,'Repetição duplicou marcações');
            echo json_encode(['ok'=>true,'rollback_cases'=>4]);break;
        case 'create':echo json_encode(['id'=>$service->saveLocalTimeEntry($base)]);break;
        case 'sync':$service->syncTimeEntry((int)$in['id'],$uid);echo '{"ok":true}';break;
        case 'edit':$service->saveLocalTimeEntry($base+[],(int)$in['id']);echo '{"ok":true}';break;
        case 'change':$base['ended_at']='10:30';$service->saveLocalTimeEntry($base,(int)$in['id']);echo '{"ok":true}';break;
        case 'delete':$service->deleteTimeEntry((int)$in['id'],$uid);echo '{"ok":true}';break;
        case 'recover':$service->recoverTimeEntry((int)$in['id'],$uid,(int)($in['remote']??0),!empty($in['absent']));echo '{"ok":true}';break;
        case 'row':echo json_encode($DB->request(['FROM'=>$table,'WHERE'=>['id'=>(int)$in['id'],'users_id'=>$uid]])->current()?:null);break;
        case 'link':
            $ticket=new Ticket();$id=(int)$ticket->add(['name'=>'QA atomic followup '.bin2hex(random_bytes(4)),'content'=>'Dados sintéticos','entities_id'=>0,'users_id_recipient'=>$uid]);
            $DB->insert('glpi_plugin_demandas_links',['tickets_id'=>$id,'openproject_work_package_id'=>98002,'openproject_status'=>'Anterior','public_phase'=>'Em análise']);$link=(int)$DB->insertId();
            echo json_encode(['ticket'=>$id,'link'=>$link]);break;
        case 'followup':(new SynchronizationService())->synchronizeByWorkPackage(98002);echo '{"ok":true}';break;
        case 'followup-fail':
            $DB->doQuery("CREATE TRIGGER operations_fail_job BEFORE UPDATE ON glpi_plugin_demandas_followup_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic job write failure'");echo '{}';break;
        case 'followup-unfail':$DB->doQuery('DROP TRIGGER IF EXISTS operations_fail_job');echo '{}';break;
        case 'followup-state':echo json_encode(['jobs'=>array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_followup_jobs','WHERE'=>['links_id'=>(int)$in['link']],'ORDER'=>'id']))),'followups'=>count($DB->request(['FROM'=>'glpi_itilfollowups','WHERE'=>['itemtype'=>'Ticket','items_id'=>(int)$in['ticket']]]))]);break;
        default:throw new RuntimeException('Modo inválido');
    }
}catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
