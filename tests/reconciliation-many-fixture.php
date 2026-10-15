<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use o banco isolado.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
spl_autoload_register(static function ($class) {
    $prefix='GlpiPlugin\\Demandas\\';
    if (str_starts_with($class,$prefix)) require '/var/www/glpi/plugins/demandas/src/'.substr($class,strlen($prefix)).'.php';
});
use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\Config as DC;
global $DB;
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if ($input['mode']==='seed') {
    $tickets=[]; $wp=random_int(9700000,9799998);
    foreach (['first','second','multiple','normal'] as $kind) {
        $DB->insert('glpi_tickets',['name'=>'QA many '.$kind.' '.$wp,'content'=>'Teste fictício','entities_id'=>0,'status'=>1,'date'=>'2026-10-15 09:00:00','date_mod'=>'2026-10-15 09:00:00']);
        $tid=(int)$DB->insertId(); $tickets[$kind]=$tid;
        $base='https://openproject.example.invalid/work_packages/';
        $url=$base.($kind==='normal'?$wp+2:$wp);
        if ($kind==='multiple') $url.=' | '.$base.($wp+1);
        $DB->insert('glpi_plugin_fields_ticketreconciliationqas',['items_id'=>$tid,'itemtype'=>'Ticket','devops'=>$url]);
    }
    TicketDemand::saveLink($tickets['first'],$wp,77,'Projeto fictício','qa',88,'Bug','Novo');
    $DB->update('glpi_plugin_demandas_links',['public_phase'=>'Fase preservada','public_message'=>'Mensagem preservada'],['tickets_id'=>$tickets['first'],'openproject_work_package_id'=>$wp]);
    $DB->insert('glpi_plugin_demandas_time_entries',['tickets_id'=>$tickets['first'],'openproject_work_package_id'=>$wp,'users_id'=>2,'spent_on'=>'2026-10-15','minutes'=>60,'comment'=>'QA tempo preservado','created_by'=>2]);
    Config::setConfigurationValues(DC::CONTEXT,['openproject_automation_api_token'=>'synthetic-personal','status_rules_json'=>'{}','webhook_secret'=>'synthetic-many-signature']);
    echo json_encode(['tickets'=>$tickets,'wp'=>$wp]);
} elseif ($input['mode']==='snapshot') {
    $data=[];
    foreach (['links','events','time_entries'] as $suffix) $data[$suffix]=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_'.$suffix,'WHERE'=>['openproject_work_package_id'=>[(int)$input['wp'],(int)$input['wp']+1,(int)$input['wp']+2]],'ORDER'=>'id'])));
    echo json_encode($data);
} elseif ($input['mode']==='sync') {
    $wp=(int)$input['wp'];
    Config::setConfigurationValues(DC::CONTEXT,['status_rules_json'=>json_encode(['99'=>['auto_followup'=>true,'private_followup'=>true,'message'=>'QA atualização da WP {{work_package.id}}']])]);
    $DB->update('glpi_plugin_demandas_links',['openproject_status'=>'Novo'],['openproject_work_package_id'=>$wp]);
    $body=json_encode(['action'=>'work_package:updated','work_package'=>['id'=>$wp]]);
    $handler=new GlpiPlugin\Demandas\WebhookHandler();
    $handler->handle($body,hash_hmac('sha256',$body,'synthetic-many-signature'));
    $tickets=array_column(TicketDemand::findAllByWorkPackage($wp),'tickets_id');
    $count=static fn()=> $DB->request(['FROM'=>'glpi_itilfollowups','WHERE'=>['items_id'=>$tickets,'itemtype'=>'Ticket','content'=>['LIKE','%QA atualização da WP '.$wp.'%']]])->count();
    $first=$count();
    $handler->handle($body,hash_hmac('sha256',$body,'synthetic-many-signature'));
    if ($first!==count($tickets) || $count()!==$first) throw new RuntimeException('Acompanhamentos duplicados ou ausentes.');
    foreach (TicketDemand::findAllByWorkPackage($wp) as $link) if ($link['openproject_status']!=='Em execução') throw new RuntimeException('Vínculo não sincronizado.');
    $invalid=false;
    try {$handler->handle($body,'invalid');} catch (GlpiPlugin\Demandas\WebhookAuthenticationException) {$invalid=true;}
    if (!$invalid) throw new RuntimeException('Assinatura inválida aceita.');
    echo json_encode(['links'=>count($tickets),'followups'=>$first,'repeated_without_duplicates'=>true]);
}
