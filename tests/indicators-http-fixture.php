<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
global $DB;
$in=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
$seed=json_decode(file_get_contents('/tmp/security-stage1-fixture.json'),true);
$ticket=(int)$seed['tickets']['visible'];$wp=990035;
if ($in['mode']==='seed') {
    $DB->updateOrInsert('glpi_plugin_demandas_links',['openproject_status'=>'Novo','last_synced_at'=>date('Y-m-d H:i:s'),'date_creation'=>'2020-01-01 00:00:00'],['tickets_id'=>$ticket,'openproject_work_package_id'=>$wp]);
    $DB->updateOrInsert('glpi_tickets_users',['use_notification'=>0],['tickets_id'=>$ticket,'users_id'=>$seed['users']['viewer'],'type'=>1]);
}
if ($in['mode']==='cleanup') {
    foreach ($DB->request(['FROM'=>'glpi_plugin_demandas_operational_findings','WHERE'=>['tickets_id'=>$ticket,'openproject_work_package_id'=>$wp]]) as $row) {
        $DB->delete('glpi_plugin_demandas_operational_actions',['operational_findings_id'=>$row['id']]);
        $DB->delete('glpi_plugin_demandas_operational_findings',['id'=>$row['id']]);
    }
    $DB->delete('glpi_plugin_demandas_links',['tickets_id'=>$ticket,'openproject_work_package_id'=>$wp]);
}
$rows=array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_operational_findings','WHERE'=>['tickets_id'=>$ticket],'ORDER'=>'id'])));
echo json_encode(['rows'=>$rows,'actions'=>array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_operational_actions','WHERE'=>['operational_findings_id'=>array_column($rows,'id') ?: [-1]],'ORDER'=>'id'])))]);
