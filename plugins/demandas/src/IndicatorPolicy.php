<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Technical indicators only; public phases and the remote open-status filter are independent. */
final class IndicatorPolicy
{
    public static function timestamp(?string $value): ?int
    {
        if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date && $date->format('Y-m-d H:i:s') === $value && $date->getTimestamp() > 0
            && $date->getTimestamp() <= time() ? $date->getTimestamp() : null;
    }

    public static function freshness(?string $date, int $days = 7): string
    {
        if ($date === null || trim($date) === '') return 'not_consulted';
        $timestamp = self::timestamp($date);
        if ($timestamp === null) return 'invalid_date';
        return time() - $timestamp >= $days * 86400 ? 'stale' : 'current';
    }

    public static function delivered(string $status): bool
    {
        return mb_strtolower(trim($status)) === 'entregue';
    }

    public static function initial(string $status, array $configured): bool
    {
        return trim($status) !== '' && in_array(ActionDashboard::normalized($status), array_map(ActionDashboard::normalized(...), $configured), true);
    }

    public static function statusKnown(string $status): bool
    {
        return trim($status) !== '' && ActionDashboard::normalized($status) !== 'nao informado';
    }

    public static function qualityLabel(string $quality): string
    {
        return match ($quality) {
            'current' => 'Sincronização recente', 'stale' => 'Dados desatualizados',
            'not_consulted' => 'Sem sincronização registrada', 'invalid_date' => 'Data de sincronização inválida ou futura',
            'unknown_status' => 'Status não informado', 'unknown_history' => 'Histórico insuficiente para medir a etapa',
            'access_denied' => 'Última tentativa recusada por autenticação/permissão no OpenProject',
            'unavailable' => 'Integração indisponível na última tentativa',
            'sync_failed' => 'Última tentativa de sincronização não concluída',
            default => 'Informação indisponível',
        };
    }

    public static function failureCategory(\Throwable $error): string
    {
        do {
            if ($error instanceof \GuzzleHttp\Exception\RequestException) {
                $status = $error->getResponse()?->getStatusCode();
                if (in_array($status, [401,403], true)) return 'access_denied';
                if ($status === null || $status >= 500) return 'unavailable';
            }
            if ($error instanceof \GuzzleHttp\Exception\ConnectException) return 'unavailable';
        } while ($error = $error->getPrevious());
        return 'sync_failed';
    }
}
