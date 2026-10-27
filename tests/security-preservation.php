<?php
declare(strict_types=1);
// Read-only fingerprints; never emit records or credentials.
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
// A version mismatch disables plugin autoload before installation; this snapshot is read-only.
require_once '/var/www/glpi/plugins/demandas/src/Config.php';
require_once '/var/www/glpi/plugins/demandas/src/CredentialStore.php';
global $DB;
$result = [];
foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $row) {
    $table = $row['TABLE_NAME'];
    if (($argv[1]??'')==='--legacy-0321' && $table==='glpi_plugin_demandas_followup_jobs') continue;
    $rows=iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']));
    if (in_array('--stage4', $argv, true)) {
        $added = $table === 'glpi_plugin_demandas_operational_findings' ? ['owner_users_id','due_date','treatment_note']
            : ($table === 'glpi_plugin_demandas_operational_runs' ? ['metrics_json'] : []);
        foreach ($rows as &$row) foreach ($added as $column) unset($row[$column]);
        unset($row);
    }
    if (($argv[1]??'')==='--legacy-0321' && $table==='glpi_plugin_demandas_time_entries') { foreach($rows as &$row) unset($row['pending_operation_json']); unset($row); }
    if (($argv[1]??'')==='--credentials' && $table==='glpi_plugin_demandas_user_tokens') { foreach($rows as &$row) $row['openproject_api_token']=GlpiPlugin\Demandas\CredentialStore::decode($row['openproject_api_token']); unset($row); }
    $result[$table] = hash('sha256', json_encode($rows));
}
foreach (['glpi_profilerights','glpi_profiles_users'] as $table) {
    if (($argv[1]??'')==='--credentials' && $table==='glpi_plugin_demandas_user_tokens') { foreach($rows as &$row) $row['openproject_api_token']=GlpiPlugin\Demandas\CredentialStore::decode($row['openproject_api_token']); unset($row); }
    $result[$table] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
}
$configs=iterator_to_array($DB->request(['FROM'=>'glpi_configs','WHERE'=>['context'=>'plugin:demandas'],'ORDER'=>'id']));
if (($argv[1]??'')==='--credentials') {
    $configs=array_values(array_filter($configs,static fn($row)=>$row['name']!=='credentials_encrypted_0340'));
    foreach($configs as &$row) if(in_array($row['name'],GlpiPlugin\Demandas\CredentialStore::KEYS,true)) $row['value']=GlpiPlugin\Demandas\CredentialStore::decode($row['value']);
    unset($row);
}
$result['configuration']=hash('sha256',json_encode($configs));
ksort($result);
echo json_encode($result, JSON_PRETTY_PRINT);
