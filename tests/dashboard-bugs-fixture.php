<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'db' || getenv('GLPI_DB_NAME') !== 'glpi_e2e') {
    throw new RuntimeException('Somente banco descartável glpi_e2e.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production', false);
$kernel->boot();
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) require '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
});
use GlpiPlugin\Demandas\Profile as DP;
use GlpiPlugin\Demandas\DeliveryReview;
global $DB;
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$rights = [DP::VIEW_BUGS_DASHBOARD, DP::VIEW_DELIVERY_REVIEW, DP::VIEW_TICKET_WP_ALERT, DP::CONFIGURE_DASHBOARD];
if ($input['mode'] === 'seed') {
    // Initial installation must not grant any new capability to existing GLPI profiles.
    foreach ($DB->request(['FROM'=>'glpi_profilerights','WHERE'=>['name'=>$rights,'profiles_id'=>[1,2,3,4,5,6,7,8]]]) as $right) {
        if ((int)$right['rights'] !== 0) throw new RuntimeException('Permissão concedida implicitamente.');
    }
    foreach (['admin','internal','readonly','client'] as $role) {
        $profile = $DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Reconciliation QA ' . $role]])->current();
        foreach ($rights as $right) DP::setRight((int)$profile['id'], $right, $role === 'admin' || ($role === 'internal' && $right === DP::VIEW_BUGS_DASHBOARD));
        $DB->update('glpi_users', ['language'=>'pt_BR'], ['name'=>'reconciliation-' . $role]);
    }
    Config::setConfigurationValues('plugin:demandas', [
        'classification_source_json'=>json_encode(['mode'=>'ticket_field','field'=>'urgency']),
        'classification_rules_json'=>json_encode(array_map(static fn($v,$label)=>['value'=>(string)$v,'label'=>$label], [1,2,3,4,5], ['Bug','Melhoria','Suporte','Sugestão de melhoria','Coletores'])),
    ]);
    $cases = [
        'completa'=>[1,1,['Entregue','Entregue']], 'parcial'=>[2,2,['Entregue','Novo']],
        'desconhecida'=>[1,4,['Entregue','']], 'pendente'=>[2,3,['Desenvolvido','Em testes']],
        'sem-wp'=>[1,1,[]], 'suporte'=>[3,1,['Entregue']], 'sugestao'=>[4,1,['Entregue']],
        'fechado'=>[1,6,['Entregue']], 'solucionado'=>[2,5,['Entregue']],
        'outra-entidade'=>[1,1,['Entregue']], 'escape'=>[1,1,['<script>alert(1)</script>']],
    ];
    $tickets=[]; $wp=94000;
    foreach ($cases as $name=>[$urgency,$status,$statuses]) {
        $title='QA029 ' . $name;
        $row=$DB->request(['FROM'=>'glpi_tickets','WHERE'=>['name'=>$title]])->current();
        $values=['name'=>$title,'content'=>'Dados exclusivamente fictícios.','entities_id'=>$name==='outra-entidade'?99999:0,'urgency'=>$urgency,'status'=>$status,'date'=>'2026-09-01 12:00:00','date_mod'=>'2026-09-01 12:00:00'];
        if ($row) { $tid=(int)$row['id']; $DB->update('glpi_tickets',$values,['id'=>$tid]); }
        else { $DB->insert('glpi_tickets',$values); $tid=(int)$DB->insertId(); }
        $tickets[$name]=$tid;
        foreach ($statuses as $opStatus) {
            $wp++;
            $DB->updateOrInsert('glpi_plugin_demandas_links', ['tickets_id'=>$tid,'openproject_project_id'=>1,'openproject_project_name'=>'Projeto fictício','openproject_type_name'=>'User Story','openproject_status'=>$opStatus,'last_synced_at'=>$name==='completa'?'2026-01-01 12:00:00':date('Y-m-d H:i:s')], ['openproject_work_package_id'=>$wp]);
        }
    }
    if (DeliveryReview::delivered('Desenvolvido') || DeliveryReview::delivered('Não Entregue') || !DeliveryReview::delivered(' Entregue ')) throw new RuntimeException('Status incorreto.');
    echo json_encode(['tickets'=>$tickets]);
} elseif ($input['mode'] === 'revoke') {
    $profile=$DB->request(['FROM'=>'glpi_profiles','WHERE'=>['name'=>'Reconciliation QA admin']])->current();
    DP::setRight((int)$profile['id'], DP::VIEW_TECHNICAL, false);
    echo '{"ok":true}';
} elseif ($input['mode'] === 'snapshot') {
    $data=[];
    foreach (['glpi_plugin_demandas_links','glpi_plugin_demandas_events','glpi_tickets','glpi_itilfollowups'] as $table) $data[$table]=hash('sha256',json_encode(iterator_to_array($DB->request(['FROM'=>$table,'ORDER'=>'id']))));
    echo json_encode($data);
} elseif ($input['mode'] === 'preferences') {
    echo json_encode(array_values(iterator_to_array($DB->request(['FROM'=>GlpiPlugin\Demandas\DashboardView::TABLE]))));
} elseif ($input['mode'] === 'read-export') {
    $file = tempnam(sys_get_temp_dir(), 'qa029-xlsx-');
    try {
        file_put_contents($file, base64_decode($input['data'], true));
        $book = (new PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($file);
        echo json_encode($book->getSheetByName('Demandas')->toArray());
    } finally { unlink($file); }
}
