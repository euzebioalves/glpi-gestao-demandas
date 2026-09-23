<?php
// API simulada; somente no contêiner descartável, fora da pasta pública do plugin.
$token = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($token === 'fixture-401' || $token === 'fixture-403') {
    http_response_code((int) substr($token, -3));
    echo json_encode(['message'=>'Falha simulada']);
    return;
}
header('Content-Type: application/hal+json');
echo json_encode(['_type'=>'Root','_embedded'=>['elements'=>[]]]);
