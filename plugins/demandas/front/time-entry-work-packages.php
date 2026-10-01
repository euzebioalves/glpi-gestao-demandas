<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\OpenProjectClient;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
AccessPolicy::check(DemandasProfile::VIEW_TIME_PORTAL);
AccessPolicy::check(DemandasProfile::LOG_OWN_TIME);
header('Content-Type: application/json; charset=utf-8');

try {
    $query = trim((string) ($_GET['query'] ?? ''));
    if ($query === '') {
        echo json_encode(['ok' => true, 'work_packages' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $results = [];
    if (mb_strlen($query) > 200) {
        throw new InvalidArgumentException('A pesquisa deve ter no máximo 200 caracteres.');
    }
    foreach (OpenProjectClient::forCurrentUser()->searchWorkPackages($query) as $workPackage) {
        $id = (int) ($workPackage['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $results[] = [
            'id' => $id,
            'subject' => trim((string) ($workPackage['subject'] ?? '')),
            'project' => trim((string) ($workPackage['_links']['project']['title'] ?? '')),
            'type' => trim((string) ($workPackage['_links']['type']['title'] ?? '')),
        ];
    }

    echo json_encode(['ok' => true, 'work_packages' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Não foi possível pesquisar as Work Packages. Confira seu token pessoal, suas permissões e a conexão com o OpenProject.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
