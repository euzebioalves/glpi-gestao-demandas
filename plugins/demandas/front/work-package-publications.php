<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\WorkPackagePublicationService as Publications;

$refresh = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $refresh ? $_POST : $_GET;
$link = Publications::checkAccess((int) ($input['ticket'] ?? 0), (int) ($input['wp'] ?? 0), $refresh);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['report' => json_decode((string) ($link['release_details_json'] ?? ''), true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } elseif ($refresh) {
        // CSRF is validated by GLPI's native firewall, including each continuation.
        $service = new Publications();
        $result = ($input['action'] ?? '') === 'start' ? $service->start($link) : $service->step($link, (string) ($input['job'] ?? ''));
        // GLPI consumes CSRF tokens; supply one for the next step.
        $result['csrf'] = Session::getNewCSRFToken();
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else { http_response_code(405); echo json_encode(['error' => 'Método não permitido.']); }
} catch (Throwable) {
    http_response_code(502);
    echo json_encode(['error' => 'Não foi possível concluir a consulta. Verifique o token pessoal, o acesso à WP e às TASKs e tente novamente. Uma consulta pode expirar ou atingir o limite de 500 tarefas/1.000 páginas. O último resultado salvo foi preservado.', 'csrf' => Session::getNewCSRFToken()]);
}
