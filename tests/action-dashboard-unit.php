<?php
declare(strict_types=1);

// Isolated rule tests: no GLPI bootstrap, database connection, HTTP or credentials.
// Native GLPI permissions/CSRF and SQL integration still require dashboard-bugs.cjs.
namespace Glpi\Exception\Http {
    class AccessDeniedHttpException extends \RuntimeException {}
    class BadRequestHttpException extends \RuntimeException {}
}
namespace GlpiPlugin\Demandas {
    class Profile {
        public const VIEW_DASHBOARD='dashboard', VIEW_TECHNICAL='technical', VIEW_BUGS_DASHBOARD='bugs';
        public const VIEW_DELIVERY_REVIEW='review', CONFIGURE_DASHBOARD='configure';
        public static array $rights=[];
        public static function has(string $right): bool { return in_array($right,self::$rights,true); }
        public static function checkRight(string $right): void { if (!self::has($right)) throw new \Glpi\Exception\Http\AccessDeniedHttpException(); }
    }
    class Config { public static function get($key,$default='') { return 'https://example.invalid'; } }
    class ClassificationPolicy { public static function resolve(\Ticket $ticket): array { return ['label'=>$ticket->fields['classification']]; } }
}
namespace {
    date_default_timezone_set('UTC');
    const READ=1;
    class Session {
        public static function getActiveEntities(): array { return [0]; }
        public static function getLoginUserID(): int { return 17; }
    }
    class CommonITILActor { const ASSIGN=2; }
    class Ticket {
        const SOLVED=5, CLOSED=6;
        public static array $records=[];
        public array $fields=[];
        public static function getAllStatusArray(): array { return [1=>'Novo',2=>'Atribuído',3=>'Planejado',4=>'Pendente',5=>'Solucionado',6=>'Fechado']; }
        public static function getStatus(int $id): string { return self::getAllStatusArray()[$id]; }
        public function getFromDB(int $id): bool { $this->fields=self::$records[$id]; return true; }
        public function can(int $id,int $right): bool { return $id!==8; }
    }
    class DBConnection { public static function getReadConnection(): FakeDB { global $DB; return $DB; } }
    class FakeDB {
        public array $rows=[], $events=[], $owners=[], $preferences=[], $queries=[];
        public function request(array $query): iterable {
            $this->queries[]=$query;
            switch ($query['FROM']) {
                case 'glpi_tickets':
                    foreach ($this->rows as $row) {
                        if (!in_array($row['entities_id'],$query['WHERE']['glpi_tickets.entities_id'],true)) continue;
                        if (isset($query['WHERE']['NOT']) && in_array($row['glpi_status'],[5,6],true)) continue;
                        yield $row;
                    }
                    break;
                case 'glpi_plugin_demandas_events':
                    foreach ($this->events as $row) if (in_array($row['tickets_id'],$query['WHERE']['tickets_id'],true)
                        && $row['is_success']===$query['WHERE']['is_success'] && in_array($row['event_type'],$query['WHERE']['event_type'],true)) yield $row;
                    break;
                case 'glpi_tickets_users':
                    foreach ($this->owners as $row) if (in_array($row['tickets_id'],$query['WHERE']['glpi_tickets_users.tickets_id'],true)) yield $row;
                    break;
                case 'glpi_plugin_demandas_dashboard_preferences':
                    foreach ($this->preferences as $row) if ($row['users_id']===$query['WHERE']['users_id']) yield $row;
                    break;
                default: throw new RuntimeException('Unexpected table');
            }
        }
        public function updateOrInsert(string $table,array $values,array $where): bool {
            if ($table!=='glpi_plugin_demandas_dashboard_preferences' || $where!==['users_id'=>17]) throw new RuntimeException('Unexpected write');
            $this->preferences=[$where+$values]; return true;
        }
    }
    require __DIR__.'/../plugins/demandas/src/ActionDashboard.php';
    require __DIR__.'/../plugins/demandas/src/ActionDashboardPanel.php';
    require __DIR__.'/../plugins/demandas/src/DeliveryReview.php';
    require __DIR__.'/../plugins/demandas/src/DashboardView.php';
    require __DIR__.'/../plugins/demandas/src/ManagementDashboardService.php';
    use GlpiPlugin\Demandas\ActionDashboard as A;
    use GlpiPlugin\Demandas\ActionDashboardPanel as Panel;
    use GlpiPlugin\Demandas\DeliveryReview as D;
    use GlpiPlugin\Demandas\DashboardView as V;
    use GlpiPlugin\Demandas\Profile as P;
    use GlpiPlugin\Demandas\ManagementDashboardService as M;
    $checks=0;
    function same($actual,$expected,string $message): void {
        global $checks; $checks++;
        if ($actual!==$expected) throw new RuntimeException($message.' '.json_encode([$actual,$expected]));
    }
    function denied(callable $call,string $message): void {
        try { $call(); } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) { same(true,true,$message); return; }
        throw new RuntimeException($message);
    }
    $now=time()-60;
    $date=static fn(int $days)=>date('Y-m-d H:i:s',$now-$days*86400);
    $event=static fn(int $days,string $status,string $previous='')=>['date_creation'=>$date($days),'new_status'=>$status,'previous_status'=>$previous];
    same(A::observedSince([$event(20,'Novo'),$event(1,'Novo','Novo')],'Novo',$date(0)),$date(20),'Repeated observations preserve start');
    same(A::observedSince([$event(20,'Novo'),$event(10,'Em testes','Novo'),$event(2,'Novo','Em testes')],'Novo',$date(0)),$date(2),'Return to stage resets clock');
    same(A::observedSince([$event(20,'Novo'),$event(2,'Novo','Em testes')],'Novo',$date(0)),$date(2),'Unobserved intervening stage resets clock');
    same(A::observedSince([$event(20,'Novo'),$event(10,''),$event(2,'Novo')],'Novo',$date(0)),$date(2),'Unknown event interrupts evidence');
    same(A::observedSince([$event(20,'Novo')],'Em testes',$date(0)),null,'Mismatched current state');
    same(A::observedSince([],'Novo',$date(0)),null,'No history does not imply zero');
    same(A::observedSince([$event(2,'Novo')],'Novo',$date(10)),null,'After last sync ignored');
    same(A::observedSince([$event(20,'Novo')],'Novo',null),null,'No sync');
    same(A::observedSince([$event(20,'Novo')],'Novo',$date(-2)),null,'Future sync not trusted');
    same(A::stage(' EM ESPECIFICAÇÃO '),'specification','Accent/case normalization');
    same(A::stage('Em Dúvida'),'blocked','Doubt');
    same(A::stage('Desenvolvido'),'validation','Developed is not delivered');
    same(A::stage('Custom status'),'other','Unknown statuses preserved');
    same(A::observedSince([$event(20,'Novo'),$event(2,'Em especificação','Novo')],'Em especificação',$date(0)),$date(20),'Status changes within stage preserve age');
    same(A::observedSince([$event(20,'Custom A'),$event(2,'Custom B','Custom A')],'Custom B',$date(0)),$date(2),'Different unrecognized statuses do not share a clock');
    same(A::observedSince([$event(20,'Novo'),$event(2,'Novo')+['event_type'=>'legacy_link_reconciled']],'Novo',$date(0)),$date(2),'A new association restarts local evidence');
    same(D::delivered('Desenvolvido'),false,'Only Entregue');
    same(D::state([['openproject_work_package_id'=>1,'openproject_status'=>'Entregue'],['openproject_work_package_id'=>2,'openproject_status'=>'']]),'partial','Unknown blocks full delivery');

    $DB=new FakeDB();
    $cases=[1=>[1,'Bug',['Entregue','Novo','Novo']],2=>[1,'Melhoria',[]],3=>[2,'Bug',['Bloqueado']],
        4=>[6,'Bug',['Novo']],5=>[5,'Melhoria',['Entregue']],6=>[1,'Suporte',['Novo']],7=>[1,'Bug',['Novo']],
        8=>[1,'Bug',['Novo']],9=>[1,'Bug',['']],10=>[1,'Melhoria',['Em especificação']],11=>[1,'Bug',['Entregue']]];
    foreach ($cases as $id=>[$status,$classification,$statuses]) {
        Ticket::$records[$id]=['classification'=>$classification];
        foreach ($statuses ?: [null] as $index=>$opStatus) $DB->rows[]=[
            'ticket_id'=>$id,'ticket_name'=>'Fictício '.$id,'glpi_status'=>$status,'entities_id'=>$id===7?7:0,
            'entity_name'=>'Empresa > Cliente fictício','opened_at'=>$date(40),'ticket_updated_at'=>$date(0),
            'openproject_work_package_id'=>$opStatus===null?null:$id*100+$index+1,'openproject_status'=>$opStatus,
            'openproject_project_name'=>'Projeto','openproject_type_name'=>'User Story','public_phase'=>'',
            'last_synced_at'=>$id===10?$date(10):$date(0),
            'work_package_details_json'=>json_encode(['responsible'=>$id===3?'':($id===1?'Ana <script>':'Bruno'),'assignee'=>'Técnico OP']),
        ];
    }
    $DB->events=[
        ['tickets_id'=>10,'openproject_work_package_id'=>1001]+$event(30,'Em especificação'),
        ['tickets_id'=>1,'openproject_work_package_id'=>102]+$event(20,'Novo'),
        ['tickets_id'=>3,'openproject_work_package_id'=>301]+$event(5,'Bloqueado'),
        ['tickets_id'=>3,'openproject_work_package_id'=>301]+$event(4,'Bloqueado','Em testes'),
        ['tickets_id'=>1,'openproject_work_package_id'=>102]+$event(2,'Novo','Novo'),
        ['tickets_id'=>1,'openproject_work_package_id'=>102,'is_success'=>0]+$event(1,'Em testes'),
        ['tickets_id'=>1,'openproject_work_package_id'=>102,'event_type'=>'work_package_publications_queried']+$event(1,'Em testes'),
    ];
    foreach ($DB->events as &$row) $row+=['is_success'=>1,'event_type'=>'work_package_synchronized']; unset($row);
    $DB->owners=[['tickets_id'=>1,'users_id'=>27,'firstname'=>'Analista','realname'=>'GLPI','name'=>'analista']];
    P::$rights=[P::VIEW_DASHBOARD,P::VIEW_TECHNICAL,P::VIEW_BUGS_DASHBOARD,P::VIEW_DELIVERY_REVIEW,P::CONFIGURE_DASHBOARD];
    $service=new M();
    $result=$service->result(['view'=>'bugs']);
    same(count($result['rows']),6,'Unique open eligible tickets only');
    same($result['summary']['total'],6,'Unique KPIs');
    same($result['actions']['counts']['partial'],1,'Partial once with three WPs');
    same($result['actions']['counts']['all_delivered'],1,'Fully delivered');
    same($result['actions']['counts']['no_wp'],1,'Missing link');
    same($result['actions']['counts']['blocked'],1,'Blocked');
    same($result['actions']['counts']['aging'],2,'Age threshold');
    same($result['actions']['stages']['specification']['count'],2,'Stage counts tickets not WPs');
    same($result['actions']['stages']['specification']['unknown'],1,'Incomplete stage evidence explicit');
    $one=array_values(array_filter($result['rows'],static fn($r)=>$r['ticket_id']===1))[0];
    same($one['work_packages'][1]['stage_days'],20,'Repeated sync/comment not reset');
    same($one['work_packages'][2]['stage_days'],null,'Missing event is null');
    $stale=array_values(array_filter($result['rows'],static fn($r)=>$r['ticket_id']===10))[0];
    same($stale['work_packages'][0]['stage_days'],20,'Stale observation ends at sync, not now');
    same($stale['attention']['stale'],A::REASONS['stale'],'Stale warning');
    same(str_contains(Panel::workPackages($one['work_packages']),'<script>'),false,'Owner/status HTML escaped');
    ob_start(); Panel::show($result['actions'],$result['filters']); $panel=ob_get_clean();
    same(str_contains($panel,'aria-label="Precisam de ação"'),true,'Attention section renders');
    same(str_contains($panel,'aria-label="Envelhecimento por etapa"'),true,'Age section renders');
    same(str_contains($panel,'<script>'),false,'Panel escapes owner values');
    foreach ($DB->queries as $query) if (in_array($query['FROM'],['glpi_plugin_demandas_events','glpi_tickets_users'],true)) {
        $ids=$query['WHERE'][$query['FROM']==='glpi_tickets_users'?'glpi_tickets_users.tickets_id':'tickets_id'];
        same(in_array(7,$ids,true)||in_array(8,$ids,true),false,'No enrichment outside authorized tickets');
    }
    $filtered=$service->result(['view'=>'bugs','op_status'=>'Entregue','delivery'=>'partial']);
    same(count($filtered['rows']),1,'Combined filters');
    same(count($filtered['rows'][0]['work_packages']),3,'All WPs restored after filter');
    same(count($service->result(['view'=>'bugs','attention'=>'no_wp'])['rows']),1,'Attention filter');
    same(count($service->result(['view'=>'bugs','stage'=>'blocked'])['rows']),1,'Stage filter');
    same(count($service->result(['view'=>'bugs','glpi_owner'=>'27'])['rows']),1,'GLPI owner filter');
    same(count($service->result(['view'=>'bugs','op_owner'=>'Ana <script>'])['rows']),1,'OP owner filter');
    same(count($service->result(['view'=>'bugs','op_owner'=>'__empty__'])['rows']),2,'Missing owner includes no-WP');
    same(count($service->result(['view'=>'bugs','attention'=>'aging','attention_days'=>21])['rows']),0,'Configurable threshold');
    same(count($service->result(['view'=>'bugs','statuses_set'=>1])['rows']),0,'Empty checkbox selection');
    same(count($service->result(['view'=>'bugs','ticket_scope'=>'all'])['rows']),8,'Explicit historical scope');
    same(count($service->result(['view'=>'bugs','ticket_scope'=>'all','attention'=>'closed_pending'])['rows']),1,'Closed divergence');
    $DB->queries=[];
    $service->result(['view'=>'general']);
    same(count(array_filter($DB->queries,static fn($q)=>$q['FROM']==='glpi_plugin_demandas_events')),0,'General view has no new history queries');
    V::save('bugs'); same(V::resolve([]),'bugs','Personal default');
    same($DB->preferences[0]['users_id'],17,'Session user only');
    P::$rights=[P::VIEW_DASHBOARD,P::VIEW_TECHNICAL,P::VIEW_BUGS_DASHBOARD];
    $restricted=$service->result(['view'=>'bugs']);
    ob_start(); Panel::show($restricted['actions'],$restricted['filters']); $panel=ob_get_clean();
    same(str_contains($panel,'attention=partial'),false,'No review links without right');
    same($restricted['delivery_counts'],[],'No delivery KPI without right');
    foreach (A::REVIEW_REASONS as $reason) {
        same($restricted['actions']['counts'][$reason],0,'No review reason without right');
        denied(fn()=>$service->result(['view'=>'bugs','attention'=>$reason]),'Direct review query denied');
    }
    denied(fn()=>$service->result(['view'=>'bugs','delivery'=>'any']),'Direct delivery filter denied');
    denied(fn()=>V::save('general'),'Default write requires explicit right');
    P::$rights=[P::VIEW_DASHBOARD,P::CONFIGURE_DASHBOARD];
    same(V::resolve([]),'general','Default fallback after revocation');
    denied(fn()=>$service->result(['view'=>'bugs']),'No technical access');
    P::$rights=[];
    denied(fn()=>$service->result(['view'=>'general']),'No dashboard access');
    echo "PASS: $checks isolated rule assertions (not native GLPI/E2E).\n";
}
