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
        // Read-only requests must not hold the GLPI session lock during API calls.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $action = $_GET['action'] ?? 'load';
        if (isset($_GET['ids']) && !is_string($_GET['ids'])) throw new InvalidArgumentException('WPs inválidas.');
        $id = static function (string $key): int {
            $value = $_GET[$key] ?? '0';
            if (!is_scalar($value) || !ctype_digit((string) $value)) throw new InvalidArgumentException('Identificador inválido.');
            return (int) $value;
        };
        if ($action === 'image') {
            \GlpiPlugin\Demandas\BacklogDetails::image($id('wp'), $id('attachment'));
            exit;
        }
        $data = match ($action) {
            'load' => (new BacklogService())->load($id('responsible')),
            'times' => ['minutes' => (new BacklogService())->times(explode(',', (string) ($_GET['ids'] ?? '')))],
            'detail' => \GlpiPlugin\Demandas\BacklogDetails::load($id('wp')),
            'people' => (static function (): array {
                \GlpiPlugin\Demandas\Profile::checkRight(\GlpiPlugin\Demandas\Profile::VIEW_COLLEAGUE_BACKLOG);
                return ['people' => \GlpiPlugin\Demandas\OpenProjectClient::forCurrentUser()->getBacklogPeople()];
            })(),
            default => throw new InvalidArgumentException('Operação inválida.'),
        };
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Método não permitido.']);
    }
} catch (\Glpi\Exception\Http\AccessDeniedHttpException $exception) {
    throw $exception;
} catch (InvalidArgumentException) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos.']);
} catch (Throwable) {
    http_response_code(502);
    echo json_encode(['error' => 'Não foi possível concluir a operação. Confira seu token pessoal e suas permissões no OpenProject e tente novamente. Suas preferências anteriores foram preservadas.']);
}
