<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonDBTM;
use CommonGLPI;
use Ticket;

final class TicketLog extends CommonDBTM
{
    public static $rightname = 'ticket';

    public static function getTypeName($nb = 0): string
    {
        return Config::label('ticket_log');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (
            $item instanceof Ticket
            && $item->getID() > 0
            && (string) Config::get('ticket_log_enabled', '0') === '1'
            && Profile::has(Profile::VIEW_TICKET_LOG)
        ) {
            return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-timeline-event-text');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (
            !$item instanceof Ticket
            || (string) Config::get('ticket_log_enabled', '0') !== '1'
            || !Profile::has(Profile::VIEW_TICKET_LOG)
            || !$item->can($item->getID(), READ)
        ) {
            return true;
        }

        $allowed = [25, 50, 100, 200];
        $perPage = (int) ($_GET['demandas_log_per_page'] ?? 25);
        if (!in_array($perPage, $allowed, true)) $perPage = 25;
        $page = max(1, (int) ($_GET['demandas_log_page'] ?? 1));
        $result = TicketLogService::page($item->getID(), $page, $perPage);
        self::render($item->getID(), $result, $allowed);
        return true;
    }

    private static function render(int $ticketId, array $result, array $allowed): void
    {
        $title = htmlspecialchars(self::getTypeName());
        echo "<div class='card m-3'><div class='card-header'><div><h3 class='card-title'>{$title}</h3><div class='text-muted small'>Linha do tempo consolidada a partir do histórico nativo do GLPI.</div></div></div><div class='card-body'>";
        echo "<form method='get' action='/front/ticket.form.php' class='d-flex align-items-end gap-2 mb-3'>";
        echo "<input type='hidden' name='id' value='{$ticketId}'><input type='hidden' name='forcetab' value='GlpiPlugin\\Demandas\\TicketLog\$1'>";
        echo "<div><label class='form-label'>Registros por página</label><select class='form-select' name='demandas_log_per_page' onchange='this.form.submit()'>";
        foreach ($allowed as $size) {
            $selected = (int) $result['per_page'] === $size ? ' selected' : '';
            echo "<option value='{$size}'{$selected}>{$size}</option>";
        }
        echo "</select></div><div class='text-muted pb-2'>" . (int) $result['total'] . " evento(s) consolidado(s)</div></form>";

        if ($result['items'] === []) {
            echo "<div class='empty'><div class='empty-icon'><i class='ti ti-history-toggle'></i></div><p class='empty-title'>Nenhuma alteração registrada</p><p class='empty-subtitle text-muted'>As próximas alterações deste chamado aparecerão aqui.</p></div>";
        } else {
            echo "<div class='table-responsive'><table class='table table-vcenter'><thead><tr><th style='width:190px'>Data e hora</th><th style='width:220px'>Responsável</th><th>Alterações realizadas</th><th style='width:120px'>Auditoria</th></tr></thead><tbody>";
            foreach ($result['items'] as $event) {
                echo '<tr><td class="text-nowrap"><i class="ti ti-clock me-1 text-primary"></i><strong>' . htmlspecialchars((string) $event['date']) . '</strong></td>';
                echo '<td><i class="ti ti-user me-1 text-muted"></i>' . htmlspecialchars(implode(', ', (array) $event['users'])) . '</td><td>';
                foreach ((array) $event['changes'] as $change) {
                    echo "<div class='mb-1'><span class='badge bg-blue-lt me-2'>" . htmlspecialchars((string) $change['field']) . "</span><span>" . htmlspecialchars((string) $change['description']) . '</span></div>';
                }
                $recordCount = (int) ($event['record_count'] ?? 1);
                $recordLabel = $recordCount === 1 ? '1 registro' : $recordCount . ' registros';
                echo '</td><td><span class="badge bg-secondary-lt" title="IDs nativos: #' . htmlspecialchars((string) $event['id'], ENT_QUOTES) . '">' . $recordLabel . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        if ((int) $result['pages'] > 1) {
            echo "<nav aria-label='Paginação do log'><ul class='pagination justify-content-center mt-3'>";
            for ($number = 1; $number <= (int) $result['pages']; $number++) {
                $active = $number === (int) $result['page'] ? ' active' : '';
                $url = '/front/ticket.form.php?' . http_build_query([
                    'id' => $ticketId,
                    'forcetab' => 'GlpiPlugin\\Demandas\\TicketLog$1',
                    'demandas_log_page' => $number,
                    'demandas_log_per_page' => (int) $result['per_page'],
                ]);
                echo "<li class='page-item{$active}'><a class='page-link' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>{$number}</a></li>";
            }
            echo '</ul></nav>';
        }
        echo '</div></div>';
    }
}
