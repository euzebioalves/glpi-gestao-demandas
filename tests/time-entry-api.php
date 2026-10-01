<?php
// Synthetic API used only by the guarded E2E fixture; no real credentials.
header('Content-Type: application/hal+json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
file_put_contents('/tmp/time-entry-api-requests.log', $method . ' ' . $path . "\n", FILE_APPEND);
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'apikey' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'synthetic-personal') {
    http_response_code(401); echo '{}'; return;
}
$wp = static fn(int $id): array => ['id'=>$id, 'subject'=>'WP fictícia sem chamado <b>&', '_links'=>[
    'project'=>['href'=>'/api/v3/projects/77','title'=>'Projeto fictício'],
    'type'=>['title'=>'User Story'], 'status'=>['title'=>'Novo'],
]];
if ($method === 'GET' && $path === '/api/v3/projects/77') {
    echo json_encode(['id'=>77,'identifier'=>'projeto-ficticio']); return;
}
if ($method === 'GET' && $path === '/api/v3/projects') {
    echo json_encode(['_embedded'=>['elements'=>[['id'=>77,'identifier'=>'projeto-ficticio']]]]); return;
}
if ($method === 'GET' && $path === '/api/v3/work_packages') {
    echo json_encode(['_embedded'=>['elements'=>[$wp(92001)]]]); return;
}
if ($method === 'GET' && preg_match('~/work_packages/(\d+)$~', $path, $match)) {
    $id=(int)$match[1];
    if (in_array($id,[92401,92403,92404],true)) { http_response_code($id-92000); echo '{}'; return; }
    echo json_encode($wp($id === 92999 ? 999 : $id)); return;
}
if ($method === 'POST' && $path === '/api/v3/time_entries/form') {
    echo json_encode(['_embedded'=>['schema'=>['activity'=>['_embedded'=>['allowedValues'=>[
        ['name'=>'Análise fictícia','_links'=>['self'=>['href'=>'/api/v3/time_entries/activities/1']]],
    ]]]], 'payload'=>[]]]); return;
}
if ($method === 'POST' && $path === '/api/v3/time_entries') {
    echo json_encode(['id'=>93001]); return;
}
if ($path === '/api/v3/time_entries/93001' && $method === 'PATCH') { echo json_encode(['id'=>93001]); return; }
if ($path === '/api/v3/time_entries/93001' && $method === 'DELETE') { http_response_code(204); return; }
http_response_code(404); echo '{}';
