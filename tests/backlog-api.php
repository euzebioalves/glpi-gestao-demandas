<?php
// Synthetic OpenProject API for the isolated GLPI tests.
header('Content-Type: application/hal+json');
$token = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($token === 'qa-401') { http_response_code(401); echo '{}'; return; }
if ($token === 'qa-403') { http_response_code(403); echo '{}'; return; }
if ($token === 'qa-500') { http_response_code(500); echo '{}'; return; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$collection = static fn($rows, $total = null) => ['total'=>$total ?? count($rows), 'pageSize'=>2, '_embedded'=>['elements'=>$rows]];
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{}'; return; }
$statuses = [['id'=>11,'name'=>'Novo'],['id'=>12,'name'=>'Em Especificação'],['id'=>13,'name'=>'Em Dúvida'],['id'=>14,'name'=>'Fechado','isClosed'=>true]];
if ($path === '/api/v3/users/me') { echo json_encode(['id'=>7]); return; }
if ($path === '/api/v3/statuses') { echo json_encode($collection($statuses)); return; }
if ($path === '/api/v3/custom_fields') { echo json_encode($collection([['id'=>9,'name'=>'Cliente']])); return; }
$filters = [];
foreach (json_decode($_GET['filters'] ?? '[]',true) as $filter) $filters += $filter;
if ($path === '/api/v3/work_packages') {
    if (($filters['responsible']['values'] ?? []) !== ['7']) { http_response_code(422); echo '{}'; return; }
    $rows=[];
    foreach ([11,12,13,14] as $status) {
        if (!in_array((string)$status,$filters['status']['values'] ?? [],true)) continue;
        $rows[]=['id'=>100+$status,'subject'=>'WP fictícia <script> teste & título','customField9'=>'Cliente fictício','description'=>['raw'=>'Descrição <img src=x onerror=alert(1)>'], '_links'=>[
            'responsible'=>['href'=>'/api/v3/users/7','title'=>'Pessoa QA'], 'status'=>['href'=>'/api/v3/statuses/'.$status,'title'=>$statuses[$status-11]['name']],
            'project'=>['href'=>'/api/v3/projects/1','title'=>'Projeto QA'], 'type'=>['href'=>'/api/v3/types/1','title'=>'Tarefa'],
        ]];
    }
    echo json_encode($collection(array_slice($rows,((int)($_GET['offset']??1)-1)*2,2),count($rows))); return;
}
if ($path === '/api/v3/time_entries') {
    if ($token === 'qa-time403') { http_response_code(403); echo '{}'; return; }
    if (($filters['user_id']['values']??[]) !== ['7'] || ($filters['entity_type']['values']??[]) !== ['WorkPackage']) { http_response_code(422); echo '{}'; return; }
    $rows=[];
    foreach ([1,2,3] as $id) $rows[]=['id'=>$id,'hours'=>$id===3?'PT99H':'PT1H30M','_links'=>['user'=>['href'=>'/api/v3/users/'.($id===3?8:7)],'entity'=>['href'=>'/api/v3/work_packages/111']]];
    echo json_encode($collection(array_slice($rows,((int)($_GET['offset']??1)-1)*2,2),count($rows)));return;
}
http_response_code(404); echo '{}';
