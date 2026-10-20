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
if ($path === '/api/v3/principals') { echo json_encode($collection([['id'=>7,'name'=>'Pessoa QA','_type'=>'User'],['id'=>8,'name'=>'Colega QA','_type'=>'User']])); return; }
if ($path === '/api/v3/attachments/1/content') { header('Content-Type: image/png'); echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3XcAAAAASUVORK5CYII='); return; }
if (preg_match('~/work_packages/(\d+)/attachments$~', $path)) { echo json_encode($collection([['id'=>1,'fileName'=>'teste.png','contentType'=>'image/png']])); return; }
if (preg_match('~/work_packages/(\d+)$~', $path, $match)) {
    if ((int)$match[1]===999) {http_response_code(403);echo '{}';return;}
    echo json_encode(['id'=>(int)$match[1], 'description'=>['raw'=>"## Descrição QA\n\n<table><tr><td>Conteúdo formatado</td></tr></table>\n\n![Imagem](/api/v3/attachments/1/content)\n\n<script>alert(1)</script><img src=\"https://example.invalid/tracker\" onerror=\"alert(1)\">"],
        '_links'=>['responsible'=>['href'=>'/api/v3/users/'.((int)$match[1]>=200?8:7)]]]);return;
}
if ($path === '/api/v3/statuses') { echo json_encode($collection($statuses)); return; }
if ($path === '/api/v3/custom_fields') { if ($token==='qa-schema') {http_response_code(403);echo '{}';return;} echo json_encode($collection([['id'=>9,'name'=>'Cliente']])); return; }
if ($path === '/api/v3/work_packages/schemas/1-1') {echo json_encode(['customField9'=>['name'=>'Cliente']]);return;}
$filters = [];
foreach (json_decode($_GET['filters'] ?? '[]',true) as $filter) $filters += $filter;
if ($path === '/api/v3/work_packages') {
    $owner = (int) ($filters['responsible']['values'][0] ?? 0);
    if (!in_array($owner,[7,8],true)) { http_response_code(422); echo '{}'; return; }
    $rows=[];
    foreach ([11,12,13,14] as $status) {
        if (!in_array((string)$status,$filters['status']['values'] ?? [],true)) continue;
        $rows[]=['id'=>($owner===7?100:200)+$status,'subject'=>'WP fictícia <script> teste & título','customField9'=>'Cliente fictício','description'=>['raw'=>'Descrição <img src=x onerror=alert(1)>'], '_links'=>[
            'responsible'=>['href'=>'/api/v3/users/'.$owner,'title'=>$owner===7?'Pessoa QA':'Colega QA'], 'status'=>['href'=>'/api/v3/statuses/'.$status,'title'=>$statuses[$status-11]['name']],
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
