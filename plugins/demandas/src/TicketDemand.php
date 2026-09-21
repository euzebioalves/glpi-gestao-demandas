<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonDBTM;
use CommonGLPI;
use Session;
use Ticket;

final class TicketDemand extends CommonDBTM
{
    public static $rightname = 'ticket';
    public $dohistory = true;

    public static function getTypeName($nb = 0): string
    {
        return Config::label('demand_evolution');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Ticket && $item->getID() > 0 && Profile::has(Profile::VIEW_PUBLIC)) {
            return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-chart-arrows-vertical');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Ticket && Profile::has(Profile::VIEW_PUBLIC)) {
            self::showForTicket($item);
        }

        return true;
    }

    public static function findByTicket(int $ticketId): ?array
    {
        return self::findAllByTicket($ticketId)[0] ?? null;
    }

    public static function findAllByTicket(int $ticketId): array
    {
        $db = \DBConnection::getReadConnection();
        $rows = [];
        $iterator = $db->request([
            'FROM' => 'glpi_plugin_demandas_links',
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => 'date_creation DESC, id DESC',
        ]);

        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function findByWorkPackage(int $workPackageId): ?array
    {
        $db = \DBConnection::getReadConnection();
        $iterator = $db->request([
            'FROM' => 'glpi_plugin_demandas_links',
            'WHERE' => ['openproject_work_package_id' => $workPackageId],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    public static function saveLink(
        int $ticketId,
        int $workPackageId,
        int $projectId,
        string $projectName,
        string $projectIdentifier,
        int $typeId,
        string $typeName,
        ?string $status,
        array $details = []
    ): void
    {
        $db = \DBConnection::getReadConnection();
        $values = [
            'tickets_id' => $ticketId,
            'openproject_work_package_id' => $workPackageId,
            'openproject_project_id' => $projectId,
            'openproject_project_name' => $projectName,
            'openproject_project_identifier' => $projectIdentifier,
            'openproject_type_id' => $typeId,
            'openproject_type_name' => $typeName,
            'openproject_status' => $status,
            'last_synced_at' => date('Y-m-d H:i:s'),
            'work_package_details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $existing = self::findByWorkPackage($workPackageId);
        if ($existing !== null) {
            $db->update('glpi_plugin_demandas_links', $values, ['id' => (int) $existing['id']]);
        } else {
            $db->insert('glpi_plugin_demandas_links', $values);
        }
    }

    public static function updateSynchronization(
        int $ticketId,
        string $status,
        string $publicPhase,
        ?string $publicMessage = null,
        array $details = [],
        ?int $workPackageId = null
    ): void {
        $db = \DBConnection::getReadConnection();
        $values = [
            'openproject_status' => $status,
            'public_phase' => $publicPhase,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
        if ($publicMessage !== null) {
            $values['public_message'] = $publicMessage;
        }
        if ($details !== []) {
            $values['work_package_details_json'] = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $where = ['tickets_id' => $ticketId];
        if ($workPackageId !== null && $workPackageId > 0) {
            $where['openproject_work_package_id'] = $workPackageId;
        }
        $db->update('glpi_plugin_demandas_links', $values, $where);
    }

    public static function history(int $ticketId, int $limit = 10): array
    {
        $db = \DBConnection::getReadConnection();
        $rows = [];
        $iterator = $db->request([
            'FROM' => 'glpi_plugin_demandas_events',
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => 'date_creation DESC, id DESC',
            'LIMIT' => $limit,
        ]);
        foreach ($iterator as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function showForTicket(Ticket $ticket): void
    {
        $links = self::findAllByTicket($ticket->getID());
        $showTechnical = Profile::has(Profile::VIEW_TECHNICAL);

        echo "<div class='card m-3'><div class='card-header'><h3 class='card-title'>" . htmlspecialchars(self::getTypeName()) . "</h3></div><div class='card-body'>";

        if ($links !== []) {
            self::showLinked($ticket, $links, $showTechnical);
            if (Profile::has(Profile::CREATE_WORK_PACKAGE) && $ticket->can($ticket->getID(), UPDATE)) {
                self::showAdditionalWorkPackageModal($ticket);
            }
            if (Profile::has(Profile::VIEW_HISTORY)) {
                self::showHistory($ticket->getID());
            }
        } elseif (Profile::has(Profile::CREATE_WORK_PACKAGE)) {
            self::showCreateForm($ticket);
        } else {
            echo "<div class='alert alert-info'>A solicitação está em análise inicial.</div>";
        }

        echo '</div></div>';
    }

    private static function showLinked(Ticket $ticket, array $links, bool $isInternal): void
    {
        $link = $links[0];
        $externalUrl = rtrim((string) Config::get('openproject_external_url', ''), '/');
        $phase = htmlspecialchars((string) $link['public_phase']);
        $message = htmlspecialchars((string) ($link['public_message'] ?? ''));

        echo "<div class='row g-3'>";
        echo "<div class='col-md-4'><strong>" . htmlspecialchars(Config::label('public_phase')) . "</strong><br>{$phase}</div>";
        if ($message !== '') {
            echo "<div class='col-md-8'><strong>Atualização</strong><br>{$message}</div>";
        }

        if ($isInternal) {
            echo "<div class='col-md-4'><strong>Work Packages vinculadas</strong><br>" . count($links) . '</div>';
            echo "<div class='col-md-4'><strong>Última sincronização</strong><br>" . self::formatDateTime((string) ($link['last_synced_at'] ?? '')) . '</div>';
            if ($ticket->can($ticket->getID(), UPDATE)) {
                echo "<div class='col-12 d-flex flex-wrap gap-2'>";
            }
            if (Profile::has(Profile::SYNC_WORK_PACKAGE) && $ticket->can($ticket->getID(), UPDATE)) {
                echo "<form method='post' action='/plugins/demandas/front/sync.form.php'>";
                echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
                echo "<input type='hidden' name='tickets_id' value='" . $ticket->getID() . "'>";
                echo "<button class='btn btn-outline-primary' type='submit'><i class='ti ti-refresh me-1'></i>Sincronizar agora</button></form>";
            }
            if (Profile::has(Profile::CREATE_WORK_PACKAGE) && $ticket->can($ticket->getID(), UPDATE)) {
                echo "<button class='btn btn-primary' type='button' data-bs-toggle='modal' data-bs-target='#demandas-create-additional-wp'>";
                echo "<i class='ti ti-plus me-1'></i>Criar nova Work Package</button>";
            }
            if ($ticket->can($ticket->getID(), UPDATE)) {
                echo '</div>';
            }
        }
        echo '</div>';

        if ($isInternal) {
            echo "<hr><h4>Work Packages vinculadas</h4><div class='table-responsive'><table class='table table-sm table-vcenter'>";
            echo '<thead><tr><th>WP</th><th>Status</th><th>Atribuído para</th><th>Responsável</th><th>Data de abertura</th><th>Projeto</th><th>Prioridade</th><th>Cliente</th><th>Tempo</th></tr></thead><tbody>';
            foreach ($links as $linked) {
                $wpId = (int) ($linked['openproject_work_package_id'] ?? 0);
                $details = json_decode((string) ($linked['work_package_details_json'] ?? ''), true);
                if (!is_array($details)) $details = [];
                // Vínculos criados por versões anteriores podem não possuir o
                // snapshot. Carrega-o sob demanda para que o novo resumo não
                // apresente campos vazios sem necessidade.
                if ($details === [] && $wpId > 0) {
                    $details = self::historyFallbackDetails($ticket->getID(), $wpId);
                }
                $url = htmlspecialchars($externalUrl . '/work_packages/' . $wpId, ENT_QUOTES);
                $status = (string) ($details['status'] ?? $linked['openproject_status'] ?? 'Não sincronizado');
                $project = (string) ($details['project'] ?? $linked['openproject_project_name'] ?? '—');
                echo '<tr>';
                echo "<td><a href='{$url}' target='_blank' rel='noopener'>#{$wpId}</a></td>";
                echo '<td>' . htmlspecialchars($status) . '</td>';
                echo '<td>' . htmlspecialchars(self::displayValue($details['assignee'] ?? null)) . '</td>';
                echo '<td>' . htmlspecialchars(self::displayValue($details['responsible'] ?? null)) . '</td>';
                echo '<td class="text-nowrap">' . self::formatDateTime((string) ($details['created_at'] ?? $linked['date_creation'] ?? '')) . '</td>';
                echo '<td>' . htmlspecialchars(self::displayValue($project)) . '</td>';
                echo '<td>' . htmlspecialchars(self::displayValue($details['priority'] ?? null)) . '</td>';
                echo '<td>' . htmlspecialchars(self::displayValue($details['customer'] ?? null)) . '</td>';
                if (AccessPolicy::has(Profile::LOG_OWN_TIME)) {
                    $entryUrl = '/plugins/demandas/front/time-entry.php?link=' . $ticket->getID() . ':' . $wpId . '&date=' . date('Y-m-d');
                    echo "<td><a class='btn btn-sm btn-outline-primary text-nowrap' href='" . htmlspecialchars($entryUrl, ENT_QUOTES) . "'><i class='ti ti-clock-plus me-1'></i>Lançar</a></td>";
                } else {
                    echo '<td>—</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    private static function displayValue(mixed $value): string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : '—';
    }

    private static function formatDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        return htmlspecialchars($timestamp !== false ? date('d/m/Y H:i:s', $timestamp) : ($value !== '' ? $value : '—'));
    }

    private static function showHistory(int $ticketId): void
    {
        $events = self::history($ticketId);
        if ($events === []) {
            return;
        }

        echo "<hr><h4>Histórico da integração</h4><div class='table-responsive'><table class='table table-sm table-vcenter'>";
        echo '<thead><tr><th>Data e hora</th><th>WP</th><th>Origem</th><th>Status da WP</th><th>' . htmlspecialchars(Config::label('public_phase')) . '</th><th class="text-center">Resultado</th><th>Detalhes</th></tr></thead><tbody>';
        foreach ($events as $event) {
            $success = (int) $event['is_success'] === 1;
            $sourceLabels = ['webhook' => 'Webhook', 'manual' => 'Manual', 'creation' => 'Criação'];
            $source = (string) ($event['source'] ?? '');
            $details = json_decode((string) ($event['details_json'] ?? ''), true);
            if (!is_array($details)) $details = [];
            $modalId = 'demandas-event-details-' . (int) $event['id'];
            echo '<tr>';
            $timestamp = strtotime((string) ($event['date_creation'] ?? ''));
            echo '<td class="text-nowrap">' . htmlspecialchars($timestamp !== false ? date('d/m/Y H:i:s', $timestamp) : (string) $event['date_creation']) . '</td>';
            $wpId = (int) ($event['openproject_work_package_id'] ?? 0);
            $wpUrl = rtrim((string) Config::get('openproject_external_url', ''), '/') . '/work_packages/' . $wpId;
            echo $wpId > 0
                ? "<td><a href='" . htmlspecialchars($wpUrl, ENT_QUOTES) . "' target='_blank' rel='noopener'>#{$wpId}</a></td>"
                : '<td>—</td>';
            echo '<td>' . htmlspecialchars($sourceLabels[$source] ?? ucfirst($source)) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($event['new_status'] ?? '—')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($event['new_public_phase'] ?? '—')) . '</td>';
            echo "<td class='text-center'><span class='d-inline-block rounded-circle " . ($success ? 'bg-success' : 'bg-danger') . "' style='width:12px;height:12px' title='" . ($success ? 'Sincronização realizada com sucesso' : 'Falha na sincronização') . "' aria-label='" . ($success ? 'Sucesso' : 'Falha') . "'></span></td>";
            echo "<td><button class='btn btn-sm btn-outline-primary' type='button' data-bs-toggle='modal' data-bs-target='#{$modalId}'><i class='ti ti-list-details me-1'></i>Visualizar</button></td>";
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        foreach ($events as $event) {
            $details = json_decode((string) ($event['details_json'] ?? ''), true);
            if (!is_array($details) || $details === []) {
                $details = self::historyFallbackDetails($ticketId, (int) ($event['openproject_work_package_id'] ?? 0));
            }
            self::showEventDetailsModal(
                'demandas-event-details-' . (int) $event['id'],
                $event,
                $details,
                (int) $event['is_success'] === 1
            );
        }
    }

    /**
     * Eventos registrados antes da versão 0.10.0 não possuem snapshot da WP.
     * Para mantê-los úteis, usa o cache atual do vínculo e, se necessário,
     * atualiza esse cache uma única vez consultando o OpenProject.
     */
    private static function historyFallbackDetails(int $ticketId, int $workPackageId = 0): array
    {
        $link = $workPackageId > 0 ? self::findByWorkPackage($workPackageId) : self::findByTicket($ticketId);
        if ($link === null) {
            return [];
        }

        $cached = json_decode((string) ($link['work_package_details_json'] ?? ''), true);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $wpId = (int) ($link['openproject_work_package_id'] ?? 0);
        if ($wpId <= 0 || !Config::isReady()) {
            return [];
        }

        try {
            $client = new OpenProjectClient();
            $details = $client->summarizeWorkPackage($client->getWorkPackage($wpId));
            if ($details !== []) {
                $db = \DBConnection::getReadConnection();
                $db->update('glpi_plugin_demandas_links', [
                    'work_package_details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ['openproject_work_package_id' => $wpId]);
            }
            return $details;
        } catch (\Throwable) {
            // A indisponibilidade momentânea do OpenProject não deve impedir
            // que o histórico básico seja exibido.
            return [];
        }
    }

    private static function showEventDetailsModal(string $modalId, array $event, array $details, bool $success): void
    {
        $wpId = (int) ($event['openproject_work_package_id'] ?? $details['id'] ?? 0);
        $wpUrl = rtrim((string) Config::get('openproject_external_url', ''), '/') . '/work_packages/' . $wpId;
        echo "<div class='modal fade' id='" . htmlspecialchars($modalId, ENT_QUOTES) . "' tabindex='-1' aria-hidden='true'><div class='modal-dialog modal-lg modal-dialog-scrollable'><div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'>Detalhes da Work Package #{$wpId}</h5><button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Fechar'></button></div><div class='modal-body'>";
        if (!$success) {
            echo "<div class='alert alert-danger'>" . htmlspecialchars((string) ($event['message'] ?? 'Falha não detalhada.')) . '</div>';
        } elseif (!empty($event['message'])) {
            echo "<div class='alert alert-warning'>" . htmlspecialchars((string) $event['message']) . '</div>';
        }
        $labels = [
            'subject' => 'Título',
            'project' => 'Projeto',
            'type' => 'Tipo',
            'status' => 'Status',
            'priority' => 'Prioridade',
            'assignee' => 'Atribuído para',
            'responsible' => 'Encarregado',
            'customer' => 'Cliente',
            'creation_reason' => 'Motivo da nova Work Package',
            'created_at' => 'Criada em',
            'updated_at' => 'Atualizada em',
        ];
        echo "<div class='row g-3'>";
        foreach ($labels as $key => $label) {
            if ($key === 'creation_reason' && empty($details[$key])) {
                continue;
            }
            $value = trim((string) ($details[$key] ?? ''));
            if ($value === '') $value = 'Não informado';
            if (in_array($key, ['created_at', 'updated_at'], true) && strtotime($value) !== false) {
                $value = date('d/m/Y H:i:s', strtotime($value));
            }
            echo "<div class='col-md-6'><div class='text-muted small'>" . htmlspecialchars($label) . "</div><div class='fw-medium'>" . htmlspecialchars($value) . '</div></div>';
        }
        echo '</div></div><div class="modal-footer">';
        if ($wpId > 0) {
            echo "<a class='btn btn-primary' href='" . htmlspecialchars($wpUrl, ENT_QUOTES) . "' target='_blank' rel='noopener'><i class='ti ti-external-link me-1'></i>Abrir no OpenProject</a>";
        }
        echo "<button type='button' class='btn btn-outline-secondary' data-bs-dismiss='modal'>Fechar</button></div></div></div></div>";
    }

    private static function showCreateForm(Ticket $ticket, bool $hasLinkedWorkPackages = false): void
    {
        if (!Config::isReady()) {
            echo "<div class='alert alert-warning'>Configure a conexão com o OpenProject antes de criar uma Work Package.</div>";
            return;
        }

        if (!Profile::has(Profile::CREATE_WORK_PACKAGE) || !$ticket->can($ticket->getID(), UPDATE)) {
            echo "<div class='alert alert-info'>Nenhuma Work Package está vinculada a este ticket.</div>";
            return;
        }

        try {
            $projects = (new OpenProjectClient())->getProjects();
        } catch (\Throwable $exception) {
            echo "<div class='alert alert-danger'>" . htmlspecialchars($exception->getMessage()) . '</div>';
            return;
        }

        $classification = ClassificationPolicy::resolve($ticket);
        echo '<p>' . ($hasLinkedWorkPackages
            ? 'Registre outra necessidade de implementação vinculada a este chamado.'
            : 'Nenhuma Work Package está vinculada a este ticket.') . '</p>';
        if ((string) (ClassificationPolicy::source()['mode'] ?? 'none') !== 'none') {
            echo "<p class='text-muted'>Classificação: <strong>" . htmlspecialchars((string) $classification['label']) . "</strong>. Somente os tipos autorizados para esta classificação serão listados.</p>";
        }
        echo "<form id='demandas-create-wp-form' method='post' action='/plugins/demandas/front/workpackage.form.php' class='row g-3 align-items-end'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
        echo "<input type='hidden' name='tickets_id' value='" . $ticket->getID() . "'>";
        if ($hasLinkedWorkPackages) {
            echo "<div class='col-12'><label class='form-label'>Motivo da nova Work Package <span class='text-danger'>*</span></label>";
            echo "<textarea class='form-control' name='additional_wp_reason' rows='3' maxlength='2000' required placeholder='Explique objetivamente por que este chamado precisa de outra Work Package.'></textarea>";
            echo "<div class='form-hint'>A justificativa será registrada na descrição da nova WP.</div></div>";
        }
        echo "<div class='col-md-6'><label class='form-label'>Projeto do OpenProject</label><select class='form-select' id='demandas-project-id' name='project_id' required>";
        echo "<option value=''>Selecione um projeto</option>";
        foreach ($projects as $project) {
            $id = (int) ($project['id'] ?? 0);
            if ($id <= 0) continue;
            echo "<option value='{$id}'>" . htmlspecialchars((string) ($project['name'] ?? ('Projeto #' . $id))) . '</option>';
        }
        echo "</select></div>";
        echo "<div class='col-md-6'><label class='form-label'>Tipo da Work Package</label><select class='form-select' id='demandas-type-id' name='type_id' required disabled><option value=''>Selecione primeiro o projeto</option></select></div>";
        echo "<div class='col-12'><div class='row g-3' id='demandas-creation-fields'></div></div>";
        echo "<div class='col-12 text-end'><button class='btn btn-primary' id='demandas-create-button' type='submit' name='create' value='1' disabled><i class='ti ti-brand-openproject me-1'></i>Criar Work Package</button></div></form>";
        echo <<<'HTML'
<script>
(() => {
    const project = document.getElementById('demandas-project-id');
    const type = document.getElementById('demandas-type-id');
    const fields = document.getElementById('demandas-creation-fields');
    const createButton = document.getElementById('demandas-create-button');
    if (!project || !type || !fields || !createButton) return;
    const ticketId = document.querySelector('#demandas-create-wp-form input[name="tickets_id"]')?.value || '';
    const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    const resetDetails = message => {
        fields.innerHTML = `<div class="col-12"><div class="alert alert-info mb-0">${escapeHtml(message)}</div></div>`;
        createButton.disabled = true;
    };
    project.addEventListener('change', async () => {
        type.disabled = true;
        type.innerHTML = '<option value="">Carregando tipos...</option>';
        if (!project.value) {
            type.innerHTML = '<option value="">Selecione primeiro o projeto</option>';
            return;
        }
        try {
            const response = await fetch('/plugins/demandas/front/openproject-options.php?action=types&project_id=' + encodeURIComponent(project.value) + '&tickets_id=' + encodeURIComponent(ticketId), {credentials: 'same-origin'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Não foi possível carregar os tipos.');
            type.innerHTML = '<option value="">Selecione um tipo</option>';
            data.types.forEach(item => type.add(new Option(item.name, item.id)));
            if (data.types.length === 0) {
                type.innerHTML = '<option value="">Nenhum tipo permitido para esta classificação</option>';
            } else {
                type.disabled = false;
                resetDetails('Selecione o tipo para carregar os demais campos exigidos pelo OpenProject.');
            }
        } catch (error) {
            type.innerHTML = '<option value="">' + error.message + '</option>';
        }
    });
    type.addEventListener('change', async () => {
        if (!project.value || !type.value) {
            resetDetails('Selecione o projeto e o tipo da Work Package.');
            return;
        }
        resetDetails('Carregando campos permitidos pelo OpenProject...');
        try {
            const url = '/plugins/demandas/front/openproject-options.php?action=creation_options&project_id=' + encodeURIComponent(project.value) + '&type_id=' + encodeURIComponent(type.value) + '&tickets_id=' + encodeURIComponent(ticketId);
            const response = await fetch(url, {credentials: 'same-origin'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Não foi possível carregar os campos.');
            fields.innerHTML = '';
            const definitions = [
                ['assignee', 'Atribuído para'],
                ['responsible', 'Encarregado'],
                ['priority', 'Prioridade'],
                ['customer', 'Cliente'],
            ];
            definitions.forEach(([key, fallbackLabel]) => {
                const field = data.fields?.[key];
                if (!field) return;
                const required = field.required ? ' required' : '';
                const requiredMark = field.required ? ' <span class="text-danger">*</span>' : '';
                const disabled = field.writable === false ? ' disabled' : '';
                const options = (field.options || []).map(option => `<option value="${escapeHtml(option.href)}"${option.href === field.selected_href ? ' selected' : ''}>${escapeHtml(option.label)}</option>`).join('');
                const column = document.createElement('div');
                column.className = 'col-md-6';
                column.innerHTML = `<label class="form-label">${escapeHtml(field.label || fallbackLabel)}${requiredMark}</label><select class="form-select" name="wp_${key}"${required}${disabled}><option value="">Selecione</option>${options}</select>`;
                fields.appendChild(column);
            });
            if (!data.fields?.customer) {
                const warning = document.createElement('div');
                warning.className = 'col-12';
                warning.innerHTML = '<div class="alert alert-warning mb-0">O campo customizado “Cliente” não foi disponibilizado pela API para este projeto e tipo. Verifique se ele está habilitado no formulário da Work Package e acessível ao usuário da integração.</div>';
                fields.appendChild(warning);
            }
            createButton.disabled = false;
        } catch (error) {
            resetDetails(error.message);
        }
    });
})();
</script>
HTML;
    }

    private static function showAdditionalWorkPackageModal(Ticket $ticket): void
    {
        echo "<div class='modal fade' id='demandas-create-additional-wp' tabindex='-1' aria-hidden='true'>";
        echo "<div class='modal-dialog modal-xl modal-dialog-scrollable'><div class='modal-content'>";
        echo "<div class='modal-header'><h5 class='modal-title'><i class='ti ti-brand-openproject me-2'></i>Criar nova Work Package</h5>";
        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Fechar'></button></div>";
        echo "<div class='modal-body'>";
        self::showCreateForm($ticket, true);
        echo '</div></div></div></div>';
    }
}
