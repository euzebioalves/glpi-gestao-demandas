<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
global $DB;
$user = $DB->request(['FROM'=>'glpi_users','WHERE'=>['name'=>'security-admin']])->current();
if (!$user) throw new RuntimeException('Prepare o fixture de segurança.');
$uid=(int)$user['id'];
GlpiPlugin\Demandas\Config::savePersonalToken($uid,'synthetic-performance');
GlpiPlugin\Demandas\CredentialStore::saveConfig(['openproject_internal_url'=>'http://127.0.0.1:8397/api/v3','openproject_external_url'=>'https://openproject.example.invalid','request_timeout'=>'8']);
$table='glpi_plugin_demandas_time_entries';
$where=['users_id'=>$uid,'spent_on'=>'2040-01-01'];
$count=count($DB->request(['FROM'=>$table,'WHERE'=>$where]));
for($i=$count;$i<55;$i++) {
    $DB->insert($table,['users_id'=>$uid,'created_by'=>$uid,'tickets_id'=>0,'openproject_work_package_id'=>98001+$i,'spent_on'=>'2040-01-01','started_at'=>'09:00:00','ended_at'=>'10:00:00','minutes'=>60,'activity_href'=>'/api/v3/time_entries/activities/1','activity_name'=>'Análise sintética','comment'=>'Teste de desempenho sintético','sync_status'=>'synced','openproject_time_entry_id'=>90001+$i,'is_success'=>1,'date_creation'=>'2026-11-19 10:00:00','date_mod'=>'2026-11-19 10:00:00']);
}
$row=$DB->request(['FROM'=>$table,'WHERE'=>$where,'ORDER'=>'id DESC','LIMIT'=>1])->current();
echo json_encode(['id'=>(int)$row['id']]);
