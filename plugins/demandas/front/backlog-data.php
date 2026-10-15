<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\BacklogHub;
use GlpiPlugin\Demandas\BacklogService;

BacklogHub::checkAccess();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    \GlpiPlugin\Demandas\Profile::checkRight(\GlpiPlugin\Demandas\Profile::CONFIGURE_BACKLOG);
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // GLPI's native firewall validates CSRF before this legacy script runs.
        (new BacklogService())->save(is_array($_POST['statuses'] ?? []) ? ($_POST['statuses'] ?? []) : ['invalid']);
        echo json_encode(['saved' => true]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode((new BacklogService())->load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido.']);
    }
} catch (Throwable) {
    http_response_code(502);
    echo json_encode(['error' => 'Não foi possível concluir a operação. Confira seu token pessoal e suas permissões no OpenProject e tente novamente. Suas preferências anteriores foram preservadas.']);
}
