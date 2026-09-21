<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\WebhookAuthenticationException;
use GlpiPlugin\Demandas\WebhookHandler;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): void {
    $protocol = str_starts_with((string) ($_SERVER['SERVER_PROTOCOL'] ?? ''), 'HTTP/')
        ? (string) $_SERVER['SERVER_PROTOCOL']
        : 'HTTP/1.1';
    $reasons = [200 => 'OK', 202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 405 => 'Method Not Allowed', 500 => 'Internal Server Error'];
    header($protocol . ' ' . $status . ' ' . ($reasons[$status] ?? ''));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $respond(200, ['application' => 'Gestão de Demandas', 'webhook' => 'ready']);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    $respond(405, ['error' => 'Método não permitido.']);
    return;
}

try {
    $rawBody = (string) file_get_contents('php://input');
    $result = (new WebhookHandler())->handle($rawBody, $_SERVER['HTTP_X_OP_SIGNATURE'] ?? null);
    $respond($result['processed'] ? 200 : 202, $result);
} catch (WebhookAuthenticationException $exception) {
    $respond(401, ['error' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    $status = str_contains($exception->getMessage(), 'não possui vínculo') ? 404 : 400;
    $respond($status, ['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    $respond(500, ['error' => 'Falha interna ao processar o webhook.']);
}
