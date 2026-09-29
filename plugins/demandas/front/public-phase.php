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
    $result = [
        'public_phase' => $link !== null ? (string) ($link['public_phase'] ?? '') : '',
        'label' => DemandasConfig::label('public_phase'),
    ];
    // A fase é pública, mas a existência, o identificador e o endereço da WP
    // são dados técnicos. Eles só seguem para perfis explicitamente autorizados.
    if (DemandasProfile::has(DemandasProfile::VIEW_TECHNICAL)) {
        $workPackageId = $link !== null ? (int) ($link['openproject_work_package_id'] ?? 0) : 0;
        $result += [
            'linked' => $link !== null,
            'work_package_id' => $workPackageId,
            'work_package_count' => count($links),
            'work_package_url' => $workPackageId > 0
                ? rtrim((string) DemandasConfig::get('openproject_external_url', ''), '/') . '/work_packages/' . $workPackageId
                : '',
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(403);
    echo json_encode(['error' => 'A fase pública não está disponível.'], JSON_UNESCAPED_UNICODE);
}
