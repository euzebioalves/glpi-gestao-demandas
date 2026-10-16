<?php
// Synthetic API: run only in the disposable container, never under the plugin.
file_put_contents('/tmp/publications-api.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/hal+json');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{}'; return; }
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'apikey' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'synthetic-personal') { http_response_code(401); echo '{}'; return; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = is_file('/tmp/publications-mode') ? trim(file_get_contents('/tmp/publications-mode')) : 'ok';
if ($mode === 'unauthorized') { http_response_code(401); echo '{}'; return; }
$page = (int) ($_GET['offset'] ?? 1);
$href = static fn($id) => ['href' => '/api/v3/work_packages/' . $id];
$collection = static fn($rows, $total, $page) => ['_type'=>'Collection', 'pageSize'=>25, 'offset'=>$page, 'count'=>count($rows), 'total'=>$total, '_embedded'=>['elements'=>$rows]];
if ($path === '/api/v3/relations') {
    $filters = json_decode($_GET['filters'] ?? '', true);
    if (($filters[0]['involved']['values'][0] ?? '') !== '93001') { http_response_code(400); echo '{}'; return; }
    $rows=[];
    for ($i=($page-1)*25+1; $i<=min($page*25,26); $i++) $rows[]=['id'=>$i,'_links'=>['from'=>$href(93001),'to'=>$href(93004)]];
    echo json_encode($collection($rows,26,$page)); return;
}
if (preg_match('~/work_packages/(\d+)/activities$~',$path,$m)) {
    $id=(int)$m[1];
    if ($mode === 'forbidden' && $id===93004) { http_response_code(403); echo '{}'; return; }
    if ($mode === 'timeout') sleep(3);
    if ($mode === 'repeat') $page=1;
    $rows=[];
    for ($i=($page-1)*25+1;$i<=min($page*25,27);$i++) {
        $raw='referenced Solicitação de pull #1842 para pontoid/sue. Sem publicação.';
        if ($mode!=='empty' && $id!==93003 && $i===26) $raw='Incluída na build **v1.112.0-beta.198** (Beta (homologação)). PR: [#1744](https://example.invalid).';
        if ($mode!=='empty' && $id===93004 && $i===27) $raw='Incluída na build **v1.112.0** (Produção). PR: #1744. <script>alert(1)</script>';
        $rows[]=['id'=>$id*100+$i,'createdAt'=>'2026-10-15T12:00:00Z','comment'=>['raw'=>$raw],'_links'=>['workPackage'=>$href($id)]];
    }
    echo json_encode($collection($rows,27,$page)); return;
}
if (preg_match('~/work_packages/(\d+)$~',$path,$m)) {
    $id=(int)$m[1];
    echo json_encode(['id'=>$mode==='identity'?999:$id,'subject'=>$id===93001?'Melhoria fictícia de frequência':'TASK fictícia '.$id.' <b>texto</b>',
        '_links'=>['children'=>$id===93001?[$href(93002),$href(93003)]:[], 'type'=>['title'=>$id===93001?'User Story':'Task'],
            'status'=>['title'=>'Desenvolvido'],'project'=>['title'=>'Projeto fictício']]]); return;
}
http_response_code(404); echo '{}';
