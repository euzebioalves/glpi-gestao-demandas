<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\WorkPackageDocumentation as Documentation;
use GlpiPlugin\Demandas\OpenProjectClient;

Session::checkLoginUser();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'page');
if ($action === 'page') {
    $ticket = max(0, (int) ($_GET['ticket'] ?? 0));
    $wp = max(0, (int) ($_GET['wp'] ?? 0));
    Documentation::access($ticket, $wp);
    if ($wp <= 0) throw new Glpi\Exception\Http\BadRequestHttpException();
    Html::header('Documentação da Work Package', $_SERVER['PHP_SELF']);
    echo '<div class="container-xl"><h1>Documentação da WP #' . $wp . '</h1><p>As alterações serão gravadas no OpenProject com seu token pessoal.</p><form class="demandas-documentation-form">';
    \GlpiPlugin\Demandas\WorkPackageDocumentationForm::render($ticket, $wp);
    echo '<div class="d-flex gap-2 my-3"><button class="btn btn-primary" type="submit">Salvar documentação</button><a class="btn btn-outline-secondary" href="' . htmlspecialchars(\GlpiPlugin\Demandas\LegacyReconciliationService::ticketReturnUrl($ticket), ENT_QUOTES) . '">Voltar ao chamado</a></div></form></div>';
    Html::footer();
    return;
}
if ($action !== 'image' && $_SERVER['REQUEST_METHOD'] !== 'POST') throw new Glpi\Exception\Http\BadRequestHttpException();
header('Cache-Control: no-store');
try {
    if ($action === 'start') {
        $id = Documentation::start((int) ($_POST['tickets_id'] ?? 0), (int) ($_POST['wp_id'] ?? 0));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['draft' => $id, 'csrf' => Session::getNewCSRFToken()], JSON_THROW_ON_ERROR);
        return;
    }
    $value = $_POST['doc_draft'] ?? $_GET['draft'] ?? '';
    $id = is_string($value) ? $value : '';
    $draft = &Documentation::draft($id);
    if ($action === 'image') {
        $attachment = (int) ($_GET['attachment'] ?? 0);
        if (!isset($draft['uploads'][$attachment]) && !isset($draft['attachments'][$attachment])) throw new Glpi\Exception\Http\AccessDeniedHttpException();
        $bytes = OpenProjectClient::forCurrentUser()->documentationImage($attachment);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new DomainException('Prévia indisponível para este formato.');
        header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff');
        echo $bytes;
        return;
    }
    $result = match ($action) {
        'load' => Documentation::load($id),
        'template' => Documentation::template($id, $_POST),
        'upload' => Documentation::upload($id, $_FILES['file'] ?? []),
        'save' => Documentation::save($id, $_POST),
        'to_markdown' => ['raw' => Documentation::markdown((string) ($_POST['documentation'] ?? ''), $id)],
        'to_html' => ['html' => Documentation::html((string) ($_POST['documentation'] ?? ''), $id)],
        default => throw new DomainException('Operação inválida.'),
    };
} catch (Glpi\Exception\Http\AccessDeniedHttpException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    http_response_code(400);
    $result = ['error' => $exception instanceof DomainException ? \GlpiPlugin\Demandas\IntegrationSecurity::message($exception) : 'Não foi possível concluir a operação no OpenProject. Confira o token, as permissões e os dados. Seu texto permanece no editor.'];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result + ['csrf' => Session::getNewCSRFToken()], JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
