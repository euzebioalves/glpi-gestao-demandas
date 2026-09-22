<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\OpenProjectClient;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\ClassificationPolicy;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::CREATE_WORK_PACKAGE);
header('Content-Type: application/json; charset=utf-8');

try {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    $ticketId = (int) ($_GET['tickets_id'] ?? 0);
    $ticket = new Ticket();
    $action = (string) ($_GET['action'] ?? '');
    if (!in_array($action, ['types', 'creation_options'], true) || $projectId <= 0 || $ticketId <= 0 || !$ticket->getFromDB($ticketId)) {
        throw new InvalidArgumentException('Projeto inválido.');
    }
    if (!$ticket->can($ticketId, UPDATE)) {
        throw new Glpi\Exception\Http\AccessDeniedHttpException();
    }

    $client = OpenProjectClient::forCurrentUser();
    if ($action === 'types') {
        $types = [];
        foreach (ClassificationPolicy::filterTypes($ticket, $client->getTypesForProject($projectId)) as $type) {
            $id = (int) ($type['id'] ?? 0);
            if ($id > 0) {
                $types[] = ['id' => $id, 'name' => (string) ($type['name'] ?? ('Tipo #' . $id))];
            }
        }
        echo json_encode(['types' => $types], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } else {
        $typeId = (int) ($_GET['type_id'] ?? 0);
        if ($typeId <= 0) throw new InvalidArgumentException('Tipo inválido.');
        echo json_encode(['fields' => $client->getCreationOptions($projectId, $typeId)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
