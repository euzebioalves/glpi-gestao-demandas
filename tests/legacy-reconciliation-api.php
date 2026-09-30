<?php
// Isolated GLPI container only, never installed in the public plugin directory.
file_put_contents('/tmp/reconciliation-api-requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/hal+json');
$id = (int) basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{}'; return; }
if ($id === 91504) sleep(3);
if (in_array($id, [91401, 91403, 91404], true)) {
    http_response_code($id - 91000); echo '{"message":"synthetic-error"}'; return;
}
echo json_encode(['id' => $id === 91999 ? 88888 : $id, 'subject' => 'WP fictícia ' . $id, 'createdAt' => '2026-09-01T12:00:00Z', 'updatedAt' => '2026-09-30T12:00:00Z', '_links' => [
    'project' => ['href' => '/api/v3/projects/77', 'title' => 'Projeto fictício'],
    'type' => ['href' => '/api/v3/types/88', 'title' => 'Bug'],
    'status' => ['href' => '/api/v3/statuses/99', 'title' => 'Em execução'],
]]);
