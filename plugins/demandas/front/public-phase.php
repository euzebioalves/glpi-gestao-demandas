<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\Config as DemandasConfig;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_PUBLIC);
header('Content-Type: application/json; charset=utf-8');

try {
    $ticketId = (int) ($_GET['tickets_id'] ?? 0);
    $ticket = new Ticket();
    if ($ticketId <= 0 || !$ticket->getFromDB($ticketId) || !$ticket->can($ticketId, READ)) {
        throw new Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $links = TicketDemand::findAllByTicket($ticketId);
    $link = $links[0] ?? null;
    echo json_encode([
        'linked' => $link !== null,
        'public_phase' => $link !== null ? (string) ($link['public_phase'] ?? '') : '',
        'label' => DemandasConfig::label('public_phase'),
        'work_package_id' => $link !== null ? (int) ($link['openproject_work_package_id'] ?? 0) : 0,
        'work_package_count' => count($links),
        'work_package_url' => $link !== null && (int) ($link['openproject_work_package_id'] ?? 0) > 0
            ? rtrim((string) DemandasConfig::get('openproject_external_url', ''), '/') . '/work_packages/' . (int) $link['openproject_work_package_id']
            : '',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(403);
    echo json_encode(['error' => 'A fase pública não está disponível.'], JSON_UNESCAPED_UNICODE);
}
