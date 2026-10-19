<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Use o banco isolado.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
spl_autoload_register(static function ($class) { $prefix='GlpiPlugin\\Demandas\\'; if (str_starts_with($class,$prefix)) require '/var/www/glpi/plugins/demandas/src/'.substr($class,strlen($prefix)).'.php'; });
use GlpiPlugin\Demandas\Config as DC;
use GlpiPlugin\Demandas\TicketDemand;
global $DB;
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
if ($input['mode']==='seed') {
    Config::setConfigurationValues(DC::CONTEXT,['openproject_internal_url'=>'http://127.0.0.1:8397/api/v3','openproject_external_url'=>'https://openproject.example.invalid','classification_source_json'=>'{}','work_package_templates_json'=>json_encode(['bug'=>"## BUG\n\nDocumentar **resultado**.\n\n```php\necho 'teste';\n```\n\n| Campo | Valor |\n| --- | --- |\n| QA | Sim |"])]);
    $DB->insert('glpi_tickets',['name'=>'QA documentação '.bin2hex(random_bytes(5)),'content'=>'Relato privado que não deve ir à WP','entities_id'=>0,'status'=>1,'date'=>'2026-10-16 09:00:00','date_mod'=>'2026-10-16 09:00:00']);$ticket=(int)$DB->insertId();
    $wp=random_int(980000,989999);TicketDemand::saveLink($ticket,$wp,77,'Projeto QA','qa',88,'Bug','Entregue');
    echo json_encode(['ticket'=>$ticket,'wp'=>$wp]);
} elseif ($input['mode']==='concurrent') {
    $file='/tmp/demandas-documentation-api.json';$state=json_decode(file_get_contents($file),true);
    $state['wps'][(int)$input['wp']]['lockVersion']++;
    file_put_contents($file,json_encode($state));echo '{}';
} elseif ($input['mode']==='first-links') {
    $wp=random_int(9400000,9499990);$tickets=[];
    foreach (['first','second'] as $kind) {
        $DB->insert('glpi_tickets',['name'=>'QA primeiro vínculo '.$kind.' '.$wp,'content'=>'QA','entities_id'=>0,'status'=>1,'date'=>'2026-10-16 09:00:00','date_mod'=>'2026-10-16 09:00:00']);$tid=(int)$DB->insertId();$tickets[$kind]=$tid;
        $urls='https://openproject.example.invalid/work_packages/'.$wp;
        if($kind==='first')$urls.=' https://openproject.example.invalid/work_packages/'.($wp+1);
        $DB->insert('glpi_plugin_fields_ticketreconciliationqas',['items_id'=>$tid,'itemtype'=>'Ticket','devops'=>$urls]);
    }
    echo json_encode(['tickets'=>$tickets,'wp'=>$wp]);
} elseif ($input['mode']==='links') {
    echo json_encode(array_values(iterator_to_array($DB->request(['FROM'=>'glpi_plugin_demandas_links','WHERE'=>['openproject_work_package_id'=>[(int)$input['wp'],(int)$input['wp']+1]]]))));
} elseif ($input['mode']==='api-state') echo file_get_contents('/tmp/demandas-documentation-api.json');
elseif ($input['mode']==='failure') {
    foreach (['/tmp/documentation-failure','/tmp/documentation-relation-failure'] as $file) if (is_file($file)) unlink($file);
    if ($input['code']??0) file_put_contents('/tmp/documentation-failure',(string)$input['code']);
    if ($input['relation']??false) file_put_contents('/tmp/documentation-relation-failure','1');
    echo '{}';
}
