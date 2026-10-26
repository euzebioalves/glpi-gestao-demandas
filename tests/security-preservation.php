<?php
declare(strict_types=1);
// Read-only fingerprints; never emit records or credentials.
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
global $DB;
$result = [];
foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $row) {
    $table = $row['TABLE_NAME'];
    $result[$table] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
}
foreach (['glpi_profilerights','glpi_profiles_users'] as $table) {
    $result[$table] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
}
$result['configuration'] = hash('sha256', json_encode(iterator_to_array($DB->request(['FROM'=>'glpi_configs','WHERE'=>['context'=>'plugin:demandas'],'ORDER'=>'id']))));
ksort($result);
echo json_encode($result, JSON_PRETTY_PRINT);
