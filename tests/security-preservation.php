<?php
declare(strict_types=1);
// Read-only fingerprints; never emit records or credentials.
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
global $DB;
$result = [];
foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $row) {
    $table = $row['TABLE_NAME'];
    if (($argv[1]??'')==='--legacy-0321' && $table==='glpi_plugin_demandas_followup_jobs') continue;
    $rows=iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']));
    if (($argv[1]??'')==='--legacy-0321' && $table==='glpi_plugin_demandas_time_entries') { foreach($rows as &$row) unset($row['pending_operation_json']); unset($row); }
    $result[$table] = hash('sha256', json_encode($rows));
}
foreach (['glpi_profilerights','glpi_profiles_users'] as $table) {
    $result[$table] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
}
$result['configuration'] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>'glpi_configs','WHERE'=>['context'=>'plugin:demandas'],'ORDER'=>'id']))));
ksort($result);
echo json_encode($result, JSON_PRETTY_PRINT);
