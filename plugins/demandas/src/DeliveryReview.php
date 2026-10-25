<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Local evidence only. No external calls, status changes or followups. */
final class DeliveryReview
{
    public const LABELS = [
        'all_delivered' => 'Todas as WPs em Entregue — conferir encerramento',
        'partial' => 'Entrega parcial — avaliar pendências',
        'none' => 'Sem WP em Entregue',
    ];

    public static function delivered(string $status): bool
    {
        return mb_strtolower(trim($status)) === 'entregue';
    }

    public static function state(array $links): string
    {
        $links = array_filter($links, static fn($link) => (int) ($link['openproject_work_package_id'] ?? 0) > 0);
        $delivered = count(array_filter($links, static fn($link) => self::delivered((string) ($link['openproject_status'] ?? ''))));
        return $delivered === 0 ? 'none' : ($delivered === count($links) ? 'all_delivered' : 'partial');
    }

    public static function wpLink(int $id): string
    {
        $label = '#' . $id;
        $base = rtrim((string) Config::get('openproject_external_url', ''), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return $label;
        return '<a target="_blank" rel="noopener noreferrer" href="'
            . htmlspecialchars($base . '/work_packages/' . $id, ENT_QUOTES) . '">' . $label . '</a>';
    }

    public static function linksHtml(array $links): string
    {
        $html = '<ul class="list-unstyled mb-0 demandas-wp-statuses">';
        foreach ($links as $link) {
            $id = (int) ($link['openproject_work_package_id'] ?? 0);
            if ($id <= 0) continue;
            $status = trim((string) ($link['openproject_status'] ?? '')) ?: 'Status não informado';
            $date = (string) ($link['last_synced_at'] ?? '');
            $ts = $date !== '' ? strtotime($date) : false;
            $stale = !$ts || $ts > time() || $ts < strtotime('-7 days');
            $html .= '<li class="mb-2">' . self::wpLink($id) . ' — <strong>' . htmlspecialchars($status, ENT_QUOTES) . '</strong>'
                . '<br><small class="text-muted">Última sincronização: ' . ($ts ? date('d/m/Y H:i', $ts) : 'não registrada')
                . ($stale ? ' — dados desatualizados; sincronize antes de decidir' : '') . '</small></li>';
        }
        return $html . '</ul>';
    }

    public static function showTicketAlert(\Ticket $ticket): void
    {
        if (!Profile::has(Profile::VIEW_TICKET_WP_ALERT) || !Profile::has(Profile::VIEW_TECHNICAL)
            || $ticket->getID() <= 0 || !$ticket->can($ticket->getID(), READ)
            || !in_array((int) ($ticket->fields['entities_id'] ?? -1), array_map('intval', \Session::getActiveEntities()), true)) return;
        $links = TicketDemand::findAllByTicket($ticket->getID());
        if (!$links) return;
        echo '<div class="alert alert-info d-block w-100 demandas-ticket-wp-alert" role="status"><strong>Status das WPs vinculadas</strong><div class="mt-2">'
            . self::linksHtml($links) . '</div><small>Dados locais da última sincronização, não consultados em tempo real. A decisão de encerrar o chamado é do analista.</small></div>';
    }
}
