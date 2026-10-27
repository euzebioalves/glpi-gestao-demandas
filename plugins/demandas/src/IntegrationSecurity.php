<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class IntegrationSecurity
{
    /** GLPI's flash-message templates render HTML with |raw. */
    public static function html(\Throwable $error): string
    {
        return htmlspecialchars(self::message($error), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Preserve deliberate plugin validation messages; never display vendor/SQL diagnostics. */
    public static function message(\Throwable $error): string
    {
        $origin = str_replace('\\', '/', $error->getFile());
        $root = str_replace('\\', '/', dirname(__DIR__)) . '/';
        if (($error instanceof \RuntimeException || $error instanceof \DomainException || $error instanceof \InvalidArgumentException)
            && str_starts_with($origin, $root) && strlen($error->getMessage()) <= 4000) return $error->getMessage();
        $reference = bin2hex(random_bytes(6));
        \Toolbox::logInFile('demandas-security', json_encode(['reference' => $reference, 'category' => 'internal'], JSON_THROW_ON_ERROR) . "\n");
        return 'Não foi possível concluir a operação. Solicite a verificação à equipe responsável. Referência: ' . $reference . '.';
    }

    /** Private hosts are intentional: administrators may configure an internal OP instance. */
    public static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new \DomainException('Informe uma URL HTTP ou HTTPS válida, sem credenciais, parâmetros ou fragmentos.');
        }
        return rtrim($url, '/');
    }

    public static function resource(string $uri): string
    {
        $path = rawurldecode(explode('?', $uri, 2)[0]);
        if (str_starts_with($uri, '/') || preg_match('~(^|/)\.\.?(?:/|$)|[\\\\:%\x00-\x20]~', $path)) {
            throw new \DomainException('O OpenProject informou um caminho de API não permitido.');
        }
        return $uri;
    }

    /** Only allowlisted metadata reaches logs; never request/response bodies, URLs or headers. */
    public static function failure(\Throwable $cause): \RuntimeException
    {
        $status = $cause instanceof \GuzzleHttp\Exception\RequestException ? $cause->getResponse()?->getStatusCode() : null;
        $reference = bin2hex(random_bytes(6));
        \Toolbox::logInFile('demandas-security', json_encode(['reference' => $reference, 'http_status' => $status, 'category' => $cause instanceof \JsonException ? 'invalid_json' : 'transport'], JSON_THROW_ON_ERROR) . "\n");
        $message = match ($status) {
            401 => 'O OpenProject recusou o token. Confira suas configurações de acesso.',
            403 => 'O usuário do token não possui permissão para esta operação no OpenProject.',
            404 => 'O recurso não foi encontrado ou não está acessível no OpenProject.',
            409 => 'O recurso foi alterado no OpenProject. Recarregue antes de continuar.',
            413 => 'O OpenProject recusou o tamanho do conteúdo enviado.',
            422 => 'O OpenProject recusou os dados. Confira os campos obrigatórios, formatos e limites de texto.',
            default => 'Falha na comunicação com o OpenProject. Confira o resultado antes de repetir uma gravação.',
        };
        return new \RuntimeException($message . ' Referência: ' . $reference . '.', 0, $cause);
    }
}
