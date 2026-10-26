<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
require_once '/var/www/glpi/plugins/demandas/setup.php';
require_once '/var/www/glpi/plugins/demandas/hook.php';
global $DB;
function securitySnapshot(): array {
    global $DB;
    $result=[];
    foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault,'TABLE_NAME'=>['LIKE','glpi_plugin_demandas_%']]]) as $row) {
        $table=$row['TABLE_NAME'];
        $result[$table]=hash('sha256',json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
    }
    foreach (['glpi_profilerights','glpi_profiles_users'] as $table) $result[$table]=hash('sha256',json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
    ksort($result);return $result;
}
if (($argv[1]??'')==='clean') {
    $target=$argv[2]??'';
    if (!preg_match('/^glpi_security_clean_[0-9_]+$/',$target)) throw new RuntimeException('Nome de schema de teste inválido.');
    if (count($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$target]]))!==0) throw new RuntimeException('O schema de teste precisa estar vazio. Nenhum dado será apagado.');
    $DB->doQuery("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
    foreach ($DB->request(['FROM'=>'information_schema.TABLES','WHERE'=>['TABLE_SCHEMA'=>$DB->dbdefault]]) as $row) {
        $table=$row['TABLE_NAME'];
        if (!preg_match('/^glpi_[a-z0-9_]+$/',$table)||str_starts_with($table,'glpi_plugin_'))continue;
        $DB->doQuery("CREATE TABLE `{$target}`.`{$table}` LIKE `{$table}`");
        if (in_array($table,['glpi_profiles','glpi_entities'],true))$DB->doQuery("INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$table}`");
    }
    $DB->doQuery("USE `{$target}`");$DB->dbdefault=$target;
    if (!plugin_demandas_install()) throw new RuntimeException('Instalação limpa falhou.');
}
$before=securitySnapshot();
for($i=0;$i<2;$i++) {
    if (!plugin_demandas_install()||securitySnapshot()!==$before) throw new RuntimeException('Atualização/repetição alterou registros ou direitos.');
}
echo json_encode(['ok'=>true,'mode'=>$argv[1]??'upgrade','preserved_tables'=>count($before)]);
