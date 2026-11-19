<?php
// Synthetic loopback API, launched only in the disposable container by the test.
header('Content-Type: application/hal+json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents('/tmp/time-performance-calls', "call\n", FILE_APPEND);
$mode = trim(@file_get_contents('/tmp/time-performance-mode') ?: 'ok');
if ($mode === 'slow') {
    file_put_contents('/tmp/time-performance-started', '1');
    sleep(4);
}
if (in_array($mode, ['401', '403', '422', '503'], true)) {
    http_response_code((int) $mode); echo '{}'; return;
}
if (preg_match('~/work_packages/(\d+)$~', $path, $m)) {
    echo json_encode(['id' => (int) $m[1], '_links' => ['project' => ['href' => '/api/v3/projects/77']]]); return;
}
if ($path === '/api/v3/projects/77') { echo '{"id":77,"identifier":"qa-project"}'; return; }
if ($path === '/api/v3/time_entries/form') {
    echo json_encode(['_embedded' => ['schema' => ['activity' => ['_embedded' => ['allowedValues' => [
        ['name' => 'Análise sintética', '_links' => ['self' => ['href' => '/api/v3/time_entries/activities/1']]],
    ]]]]]]); return;
}
http_response_code(404); echo '{}';
