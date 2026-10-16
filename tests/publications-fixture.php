<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'db' || getenv('GLPI_DB_NAME') !== 'glpi_e2e') throw new RuntimeException('Somente base fictícia isolada.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
global $DB;
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\Config as DC;
use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\WorkPackagePublicationService as Publications;
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if ($input['mode']==='seed') {
    $users=[];
    foreach (['admin','internal','client','readonly'] as $role) {
        $u=$DB->request(['FROM'=>'glpi_users','WHERE'=>['name'=>'reconciliation-'.$role]])->current();
        $p=$DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Reconciliation QA '.$role]])->current();
        DP::setRight((int)$p['id'],DP::SYNC_WORK_PACKAGE,in_array($role,['admin','internal'],true));
        DP::setRight((int)$p['id'],DP::VIEW_HISTORY,$role!=='client');
        $DB->update('glpi_users',['firstname'=>'Pessoa','realname'=>'Teste '.$role,'language'=>'pt_BR'],['id'=>(int)$u['id']]);
        $users[$role]=(int)$u['id'];
    }
    Config::setConfigurationValues(DC::CONTEXT,['openproject_internal_url'=>'http://127.0.0.1:8395/api/v3','request_timeout'=>2]);
    $DB->insert('glpi_tickets',['name'=>'QA publicações SGE','content'=>'Somente dados fictícios','entities_id'=>0,'status'=>1,'date'=>'2026-10-15 12:00:00','date_mod'=>'2026-10-15 12:00:00']);
    $tid=(int)$DB->insertId();
    TicketDemand::saveLink($tid,93001,77,'Projeto fictício','qa',88,'User Story','Desenvolvido',['subject'=>'Melhoria fictícia de frequência','status'=>'Desenvolvido']);
    $DB->insert('glpi_plugin_demandas_events',['tickets_id'=>$tid,'openproject_work_package_id'=>93001,'event_type'=>'legacy_link_reconciled','source'=>'legacy_reconciliation','is_success'=>1,
        'message'=>'Vínculo de Atividade DevOps conciliado por usuário #'.$users['admin'].'. Nenhuma WP foi criada.']);
    echo json_encode(['ticket'=>$tid,'users'=>$users]);
} elseif ($input['mode']==='api-mode') {
    if (!in_array($input['value'],['ok','unauthorized','forbidden','timeout','repeat','identity','empty'],true)) throw new RuntimeException('Modo inválido');
    file_put_contents('/tmp/publications-mode',$input['value']); echo '{}';
} elseif ($input['mode']==='snapshot') {
    $link=TicketDemand::findByWorkPackage(93001,(int)$input['ticket']);
    echo json_encode(['report'=>$link['release_details_json'],'events'=>count(TicketDemand::history((int)$input['ticket'],10000))]);
} elseif ($input['mode']==='sync') {
    $tid=(int)$input['ticket'];
    $_SESSION['glpiID']=(int)$DB->request(['FROM'=>'glpi_users','WHERE'=>['name'=>'reconciliation-internal']])->current()['id'];
    $before=TicketDemand::findByWorkPackage(93001,$tid);
    $followups=$DB->request(['FROM'=>'glpi_itilfollowups','WHERE'=>['items_id'=>$tid,'itemtype'=>'Ticket']])->count();
    for ($i=0;$i<2;$i++) {
        (new GlpiPlugin\Demandas\SynchronizationService())->synchronize($tid,'manual');
        $event=TicketDemand::history($tid,1)[0];
        $details=json_decode($event['details_json'],true);
        if ($details['sge_publications']!==json_decode($before['release_details_json'],true)
            || $details['actor_name']!=='Pessoa Teste internal'
            || !(int)$event['is_success']) throw new RuntimeException('Snapshot da sincronização inválido');
    }
    $after=TicketDemand::findByWorkPackage(93001,$tid);
    if ($before['release_details_json']!==$after['release_details_json'] || $before['public_phase']!==$after['public_phase']
        || $followups!==$DB->request(['FROM'=>'glpi_itilfollowups','WHERE'=>['items_id'=>$tid,'itemtype'=>'Ticket']])->count()) throw new RuntimeException('Sincronização alterou publicação/fase/acompanhamentos');
    echo json_encode(['ok'=>true]);
} elseif ($input['mode']==='upgrade') {
    require_once '/var/www/glpi/plugins/demandas/hook.php';
    $snapshot = static function () use ($DB): array {
        $data=[];
        foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $table) {
            $name=$table['TABLE_NAME'];
            $data[$name]=array_values(iterator_to_array($DB->request(['FROM'=>$name,'ORDER'=>'id'])));
        }
        ksort($data); return $data;
    };
    $before=$snapshot();
    // Only this disposable synthetic database: simulate the sole 0.27 -> 0.28 schema difference.
    file_put_contents('/tmp/publications-upgrade-backup.json',json_encode($before,JSON_THROW_ON_ERROR));
    $DB->doQuery('ALTER TABLE glpi_plugin_demandas_links DROP COLUMN release_details_json');
    if (!plugin_demandas_install()) throw new RuntimeException('Migração falhou');
    $expected=$before;
    foreach ($expected['glpi_plugin_demandas_links'] as &$row) $row['release_details_json']=null;
    unset($row);
    if ($snapshot()!=$expected) throw new RuntimeException('Migração alterou registros anteriores');
    foreach ($before['glpi_plugin_demandas_links'] as $row) {
        $DB->update('glpi_plugin_demandas_links',['release_details_json'=>$row['release_details_json'],'date_mod'=>$row['date_mod']],['id'=>$row['id']]);
    }
    for ($i=0;$i<2;$i++) {
        if (!plugin_demandas_install() || $snapshot()!=$before) throw new RuntimeException('Repetição alterou dados');
    }
    $body='{"action":"test:ignored"}';
    $handler=new GlpiPlugin\Demandas\WebhookHandler();
    $result=$handler->handle($body,'sha256='.hash_hmac('sha256',$body,DC::webhookSecret()));
    if ($result['processed']!==false) throw new RuntimeException('Webhook válido');
    $denied=false;
    try { $handler->handle($body,'invalid'); } catch (GlpiPlugin\Demandas\WebhookAuthenticationException) { $denied=true; }
    if (!$denied) throw new RuntimeException('Webhook inválido aceito');
    echo 'PASS migração 0.27, repetição, '.count($before).' tabelas preservadas e webhook';
} elseif ($input['mode']==='unit') {
    $check=static function($ok,$message) { if (!$ok) throw new RuntimeException($message); };
    $check(Publications::parseComment('referenced PR #1842 version v1.112.0')===[],'PR não é publicação');
    $check(Publications::parseComment('Incluída na build **v1.112.0-beta.198** (Beta (homologação)).')[0]===['version'=>'v1.112.0-beta.198','environment'=>'Homologação'],'Beta');
    $check(Publications::parseComment('<p>Incluída na build <strong>v1.112.0</strong> (Produção).</p>')[0]['environment']==='Produção','HTML');
    $check(count(Publications::parseComment("Incluída na build v1.1.0 (Produção).\nIncluída na build v1.2.0-beta.1 (homologação)."))===2,'Múltiplas builds');
    $check(Publications::parseComment('Incluída na build v1.2.x (Produção).')===[],'Versão inválida');
    $check(GlpiPlugin\Demandas\ManagementDashboardService::clientLabel('PONTO ID > CLIENTE QA > UNIDADE')==='CLIENTE QA','Cliente segundo nível');
    $check(GlpiPlugin\Demandas\ManagementDashboardService::clientLabel('Raiz')==='Raiz','Raiz');
    echo 'PASS parser e apresentação do cliente';
}
