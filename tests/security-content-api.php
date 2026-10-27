<?php
// Disposable loopback server. Never records Authorization or payloads.
if (str_contains($_SERVER['REQUEST_URI'], '/redirect-target')) {
    file_put_contents('/tmp/demandas-security-redirect-hit','1');
    echo '{}'; return;
}
$mode = trim((string) @file_get_contents('/tmp/demandas-security-mode'));
if ($mode === 'redirect') { header('Location: http://127.0.0.1:8399/redirect-target', true, 302); return; }
header('Content-Type: application/json');
if ($mode === 'large') { echo '{"data":"' . str_repeat('x',17*1024*1024) . '"}'; return; }
if ($mode === 'invalid') { echo '{'; return; }
echo '{"_embedded":{"elements":[]}}';
