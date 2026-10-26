<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use somente o banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
$input=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
global $DB;
$profiles=[];
foreach (['admin','internal','readonly','client'] as $role) {
    $row=$DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Reconciliation QA '.$role]])->current();
    if (!$row) throw new RuntimeException('Execute a fixture de conciliação primeiro.');
    $profiles[$role]=(int)$row['id'];
    if ($input['mode']==='seed') $DB->updateOrInsert('glpi_profilerights',['rights'=>$role==='admin' ? READ|UPDATE : ($role==='readonly' ? READ : 0)],['profiles_id'=>$profiles[$role],'name'=>'profile']);
}
$rights=[];
foreach ($DB->request(['FROM'=>'glpi_profilerights','WHERE'=>['profiles_id'=>$profiles['internal']],'ORDER'=>'name']) as $row) $rights[$row['name']]=$row['rights'];
echo json_encode(['profiles'=>$profiles,'rights'=>$rights]);
