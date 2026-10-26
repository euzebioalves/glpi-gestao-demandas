<?php
// Synthetic remote service, only inside the disposable test container.
header('Content-Type: application/hal+json');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
$mode=trim(@file_get_contents('/tmp/operations-api-mode')?:'ok');
$state=json_decode(@file_get_contents('/tmp/operations-api-state')?:'{}',true);
$state+=['posts'=>0,'patches'=>0,'deletes'=>0,'entries'=>[]];
$persist=static function()use(&$state){file_put_contents('/tmp/operations-api-state',json_encode($state));};
if($path==='/api/v3/users/me'){echo '{"id":77}';return;}
if(preg_match('~/work_packages/(\d+)$~',$path,$m)){
    echo json_encode(['id'=>(int)$m[1],'subject'=>'Operação sintética','_links'=>['project'=>['href'=>'/api/v3/projects/77','title'=>'QA'],'status'=>['href'=>'/api/v3/statuses/1','title'=>$mode==='status2'?'Em desenvolvimento':'Novo']]]);return;
}
if($path==='/api/v3/time_entries'&&$method==='POST'){
    $state['posts']++;
    if(in_array($mode,['401','403','422'],true)){$persist();http_response_code((int)$mode);echo '{}';return;}
    $id=970000+$state['posts'];$entry=json_decode(file_get_contents('php://input'),true);
    $entry['id']=$id;$entry['_links']['user']??=['href'=>'/api/v3/users/77'];$state['entries'][$id]=$entry;$persist();
    if($mode==='slow')usleep(1500000);
    if($mode==='timeout')sleep(4);
    if($mode==='lost'){echo '{';return;}
    echo json_encode($entry);return;
}
if(preg_match('~/time_entries/(\d+)$~',$path,$m)){
    $id=(int)$m[1];
    if(!isset($state['entries'][$id])){http_response_code(404);echo '{}';return;}
    if($method==='GET'){echo json_encode($state['entries'][$id]);return;}
    if($method==='PATCH'){$state['patches']++;$state['entries'][$id]=json_decode(file_get_contents('php://input'),true)+['id'=>$id];$state['entries'][$id]['_links']['user']??=['href'=>'/api/v3/users/77'];$persist();if($mode==='lost'){echo '{';return;}echo json_encode($state['entries'][$id]);return;}
    if($method==='DELETE'){$state['deletes']++;unset($state['entries'][$id]);$persist();if($mode==='lost'){echo '{';return;}http_response_code(204);return;}
}
http_response_code(404);echo '{}';
