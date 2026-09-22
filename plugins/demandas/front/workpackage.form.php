<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\OpenProjectClient;
use GlpiPlugin\Demandas\SynchronizationService;
use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::CREATE_WORK_PACKAGE);

$ticketId = (int) ($_POST['tickets_id'] ?? 0);
$projectId = (int) ($_POST['project_id'] ?? 0);
$typeId = (int) ($_POST['type_id'] ?? 0);
$additionalReason = trim((string) ($_POST['additional_wp_reason'] ?? ''));
$selection = [];
foreach (['assignee', 'responsible', 'priority', 'customer'] as $field) {
    $selection[$field] = trim((string) ($_POST['wp_' . $field] ?? ''));
}
$ticket = new Ticket();

if ($ticketId <= 0 || !$ticket->getFromDB($ticketId)) {
    throw new Glpi\Exception\Http\NotFoundHttpException();
}
if (!$ticket->can($ticketId, UPDATE)) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}
if ($projectId <= 0 || $typeId <= 0) {
    Session::addMessageAfterRedirect('Selecione o projeto e o tipo da Work Package.', true, ERROR);
    Html::redirect('/front/ticket.form.php?id=' . $ticketId);
}
$existingLinks = TicketDemand::findAllByTicket($ticketId);
if ($existingLinks !== [] && $additionalReason === '') {
    Session::addMessageAfterRedirect('Informe o motivo para criar outra Work Package para este chamado.', true, ERROR);
    Html::redirect('/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\\Demandas\\TicketDemand$1');
}
if (mb_strlen($additionalReason) > 2000) {
    Session::addMessageAfterRedirect('O motivo da nova Work Package deve possuir no máximo 2.000 caracteres.', true, ERROR);
    Html::redirect('/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\\Demandas\\TicketDemand$1');
}
try {
    $workPackage = OpenProjectClient::forCurrentUser()->createWorkPackage(
        $ticket,
        $projectId,
        $typeId,
        $selection,
        $additionalReason
    );
    $metadata = $workPackage['_demandas'];
    TicketDemand::saveLink(
        $ticketId,
        (int) $workPackage['id'],
        (int) $metadata['project_id'],
        (string) $metadata['project_name'],
        (string) ($metadata['project_identifier'] ?? ''),
        (int) $metadata['type_id'],
        (string) $metadata['type_name'],
        null,
        (array) ($metadata['details'] ?? [])
    );
    // Executa também a regra do status inicial (por exemplo, Novo → Em análise).
    (new SynchronizationService())->synchronizeByWorkPackage((int) $workPackage['id'], 'creation');
    Session::addMessageAfterRedirect('Work Package criada e vinculada com sucesso.', true, INFO);
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
}

Html::redirect('/front/ticket.form.php?id=' . $ticketId . '&forcetab=GlpiPlugin\\Demandas\\TicketDemand$1');
