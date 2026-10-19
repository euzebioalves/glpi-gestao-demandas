<?php
// Synthetic OpenProject API; run only in the disposable GLPI container on 8397.
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') exit(1);
$file = '/tmp/demandas-documentation-api.json';
$state = is_file($file) ? json_decode(file_get_contents($file), true) : ['wps' => [], 'attachments' => [], 'relations' => [], 'creates' => 0, 'updates' => 0];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$response = [];
function wp(int $id, string $raw = '## Documentação original'): array {
    return ['id'=>$id,'subject'=>'WP sintética','lockVersion'=>1,'description'=>['format'=>'markdown','raw'=>$raw], '_links'=>[
        'project'=>['href'=>'/api/v3/projects/77','title'=>'Projeto QA'], 'type'=>['href'=>'/api/v3/types/88','title'=>'Bug'],
        'status'=>['href'=>'/api/v3/statuses/99','title'=>'Entregue'], 'attachments'=>[],
    ]];
}
if (is_file('/tmp/documentation-failure') && !str_contains($path, 'attachments/')) {
    $code = (int) file_get_contents('/tmp/documentation-failure');
    http_response_code($code); echo json_encode(['message'=>'Falha sintética']); exit;
}
if ($path === '/api/v3/projects') $response=['_embedded'=>['elements'=>[['id'=>77,'name'=>'Projeto QA']]]];
elseif ($path === '/api/v3/projects/77') $response=['id'=>77,'name'=>'Projeto QA','identifier'=>'qa'];
elseif ($path === '/api/v3/projects/77/types') $response=['_embedded'=>['elements'=>[['id'=>88,'name'=>'Bug']]]];
elseif ($path === '/api/v3/custom_fields') $response=['_embedded'=>['elements'=>[]]];
elseif ($path === '/api/v3/work_packages/form') $response=['_embedded'=>['schema'=>[], 'payload'=>[]]];
elseif ($path === '/api/v3/attachments' && $method === 'POST') {
    $id=100+count($state['attachments']); $metadata=json_decode($_POST['metadata']??'{}',true);
    $bytes=file_get_contents($_FILES['file']['tmp_name']);
    $response=['id'=>$id,'fileName'=>$metadata['fileName'],'contentType'=>(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)];
    $state['attachments'][$id]=$response+['bytes'=>base64_encode($bytes)];
} elseif (preg_match('~/attachments/(\d+)/content$~',$path,$m)) {
    $attachment=$state['attachments'][(int)$m[1]]??null;
    if (!$attachment) {http_response_code(404);exit;}
    header('Content-Type: '.$attachment['contentType']);echo base64_decode($attachment['bytes']);exit;
} elseif ($path === '/api/v3/work_packages' && $method === 'POST') {
    $id=990000+$state['creates']++;$response=wp($id,$input['description']['raw']);
    $response['_links']['attachments']=$input['_links']['attachments']??[];
    $state['wps'][$id]=$response;
} elseif (preg_match('~/work_packages/(\d+)/attachments$~',$path,$m)) {
    $response=['_embedded'=>['elements'=>array_map(fn($a)=>$state['attachments'][(int)basename($a['href'])],$state['wps'][(int)$m[1]]['_links']['attachments']??[])]];
} elseif ($path === '/api/v3/relations') $response=['_embedded'=>['elements'=>array_values($state['relations'])]];
elseif (preg_match('~/work_packages/(\d+)/relations$~',$path,$m)) {
    if (is_file('/tmp/documentation-relation-failure')) {http_response_code(403);echo '{}';exit;}
    $response=['id'=>count($state['relations'])+1,'type'=>$input['type'],'_links'=>['from'=>['href'=>'/api/v3/work_packages/'.$m[1]],'to'=>$input['_links']['to']]];
    $state['relations'][]=$response;
} elseif (preg_match('~/work_packages/(\d+)$~',$path,$m)) {
    $id=(int)$m[1]; $state['wps'][$id]??=wp($id); $response=$state['wps'][$id];
    if ($method==='PATCH') {
        if ($input['lockVersion']!==$response['lockVersion']) {http_response_code(409);echo '{}';exit;}
        $response['lockVersion']++;$response['description']=$input['description'];$response['_links']['attachments']=$input['_links']['attachments'];
        $state['wps'][$id]=$response;$state['updates']++;
    }
} else {http_response_code(404);$response=['message'=>'Not found'];}
file_put_contents($file,json_encode($state));header('Content-Type: application/json');echo json_encode($response);
