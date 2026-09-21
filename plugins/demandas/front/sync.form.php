<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\SynchronizationService;
use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::SYNC_WORK_PACKAGE);

$ticketId = (int) ($_POST['tickets_id'] ?? 0);
$ticket = new Ticket();
if ($ticketId <= 0 || !$ticket->getFromDB($ticketId)) {
    throw new Glpi\Exception\Http\NotFoundHttpException();
}
if (!$ticket->can($ticketId, UPDATE)) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}
if (TicketDemand::findByTicket($ticketId) === null) {
    Session::addMessageAfterRedirect('Este ticket não possui Work Package vinculada.', true, WARNING);
    Html::redirect('/front/ticket.form.php?id=' . $ticketId);
}

try {
    $result = (new SynchronizationService())->synchronize($ticketId);
    $count = count((array) ($result['work_packages'] ?? []));
    Session::addMessageAfterRedirect(
        'Sincronização concluída para ' . $count . ' Work Package(s).',
        true,
        INFO
    );
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
}

Html::redirect('/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\\Demandas\\TicketDemand$1');
