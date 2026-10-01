<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\LegacyReconciliationService;
use GlpiPlugin\Demandas\ManagementDashboard;
use GlpiPlugin\Demandas\WorkPackageMonitoringList;

LegacyReconciliationService::checkAccess();
$all = ($_GET['ticket_scope'] ?? '') === 'all';
$ticketId = is_scalar($_GET['ticket'] ?? null) ? max(0, (int) $_GET['ticket']) : 0;
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$externalUrl = rtrim((string) \GlpiPlugin\Demandas\Config::get('openproject_external_url', ''), '/');
try {
    $rows = (new LegacyReconciliationService())->preview($all, $ticketId);
} catch (Throwable) {
    $rows = [];
    $error = 'Não foi possível ler Atividade DevOps. Solicite a conferência do campo no plugin Fields.';
}
$page = WorkPackageMonitoringList::paginate($rows, ['page' => $_GET['page'] ?? 1, 'per_page' => 25]);
$labels = ['ready' => 'Disponível para validar e conciliar', 'linked' => 'Já conciliada', 'conflict' => 'Conflito de vínculo — revisão necessária', 'invalid' => 'URL inválida ou de outra instância', 'readonly' => 'Sem permissão de alteração do chamado'];
Html::header('Conciliar Work Packages existentes', $_SERVER['PHP_SELF'], 'management', ManagementDashboard::class);
echo "<div class='container-xl'><h1>Conciliar Work Packages existentes</h1><p>Prévia de Atividade DevOps. Selecione até cinco referências por vez. A confirmação valida cada WP com seu token pessoal e registra somente o vínculo no GLPI, sem criar ou alterar WPs, fases públicas ou acompanhamentos.</p><a class='btn btn-outline-secondary mb-3' href='/plugins/demandas/front/dashboard.php'>Voltar à visão gerencial</a>";
echo "<form method='get' class='card card-body mb-3'><div class='row g-3 align-items-end'><div class='col-md-6'><label for='scope' class='form-label'>Escopo dos chamados</label><select id='scope' name='ticket_scope' class='form-select'><option value='open'" . (!$all ? ' selected' : '') . ">Abertos</option><option value='all'" . ($all ? ' selected' : '') . ">Todos, inclusive solucionados e fechados</option></select></div><div class='col-md-4'><label class='form-label' for='ticket'>Número do chamado (opcional)</label><input id='ticket' class='form-control' type='number' min='1' name='ticket' value='" . ($ticketId ?: '') . "'></div><div class='col-md-2'><button class='btn btn-primary'>Filtrar</button></div></div></form>";
if (isset($error)) echo "<div class='alert alert-danger'>{$h($error)}</div>";
if ($rows === []) {
    echo "<div class='alert alert-info'>Nenhuma referência encontrada neste escopo. Confira o campo textual Atividade DevOps no plugin Fields e as permissões de leitura dos chamados.</div>";
} else {
    echo "<p>Exibindo {$page['first']}–{$page['last']} de {$page['total']} referências. A cobertura do painel será atualizada após a confirmação dos vínculos.</p><form method='post' action='/plugins/demandas/front/legacy-reconciliation.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='ticket_scope' value='" . ($all ? 'all' : 'open') . "'><div class='card table-responsive'><table class='table table-vcenter'><thead><tr><th>Selecionar</th><th>Chamado</th><th>WP indicada</th><th>Situação</th></tr></thead><tbody>";
    foreach ($page['rows'] as $row) {
        $tid = $row['ticket_id']; $wp = $row['wp_id'];
        $wpCell = $wp > 0
            ? "<a href='{$h($externalUrl . '/work_packages/' . $wp)}' target='_blank' rel='noopener' title='Abrir WP #{$wp} no OpenProject'>#{$wp}<i class='ti ti-external-link ms-1' aria-hidden='true'></i></a>"
            : '—';
        $detailsButton = '';
        if ($row['state'] === 'conflict') {
            $encoded = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $detailsButton = "<button class='btn btn-sm btn-outline-secondary ms-2 legacy-conflict-details' type='button' data-conflict='{$encoded}' data-bs-toggle='modal' data-bs-target='#legacyConflictDetails'>Detalhes</button>";
        }
        echo '<tr><td>' . ($row['state'] === 'ready' ? "<input class='form-check-input' type='checkbox' name='pairs[]' value='{$tid}:{$wp}' aria-label='Conciliar chamado {$tid} com WP {$wp}'>" : '—') . "</td><td><a href='/front/ticket.form.php?id={$tid}' target='_blank' rel='noopener'>#{$tid} — {$h($row['ticket_name'])}</a></td><td>{$wpCell}</td><td>{$h($labels[$row['state']])}{$detailsButton}</td></tr>";
    }
    echo "</tbody></table></div><label class='form-check my-3'><input class='form-check-input' type='checkbox' name='confirm' value='1' required><span class='form-check-label'>Conferi os chamados e confirmo a vinculação às WPs existentes selecionadas.</span></label><button class='btn btn-primary'>Validar e conciliar selecionadas</button></form>";
    echo "<nav class='d-flex flex-wrap gap-3 my-3' aria-label='Paginação da conciliação'>";
    foreach (['Anterior' => $page['page'] - 1, 'Próxima' => $page['page'] + 1] as $label => $target) {
        if ($target < 1 || $target > $page['pages']) continue;
        $query = http_build_query(['page' => $target, 'ticket' => $ticketId, 'ticket_scope' => $all ? 'all' : 'open']);
        echo "<a href='?{$h($query)}'>{$label}</a>";
    }
    echo "<span>Página {$page['page']} de {$page['pages']}</span></nav>";
}
echo "<div class='modal fade' id='legacyConflictDetails' tabindex='-1' aria-labelledby='legacyConflictTitle' aria-hidden='true'><div class='modal-dialog modal-lg modal-dialog-centered'><div class='modal-content'><div class='modal-header'><h2 class='modal-title h4' id='legacyConflictTitle'>Detalhes do conflito de conciliação</h2><button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Fechar'></button></div><div class='modal-body' id='legacy-conflict-content'></div><div class='modal-footer'><button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Fechar</button></div></div></div></div>";
$modalConfig = json_encode([
    'csrf' => Session::getNewCSRFToken(),
    'scope' => $all ? 'all' : 'open',
    'external_url' => $externalUrl,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$modalScript = <<<'JS'
<script>
const legacyConflictConfig = __CONFIG__;
const legacyConflictContent = document.getElementById('legacy-conflict-content');
const legacyEscape = value => { const element = document.createElement('span'); element.textContent = String(value ?? ''); return element.innerHTML; };
const legacyTicketLink = ticket => ticket?.visible ? '<a href="/front/ticket.form.php?id=' + Number(ticket.id) + '" target="_blank" rel="noopener">#' + Number(ticket.id) + ' — ' + legacyEscape(ticket.name) + '</a>' : 'um chamado sem acesso no escopo atual';
document.querySelectorAll('.legacy-conflict-details').forEach(button => button.addEventListener('click', () => {
  let row;
  try { row = JSON.parse(button.dataset.conflict || '{}'); } catch (_) { legacyConflictContent.textContent = 'Não foi possível carregar os detalhes do conflito.'; return; }
  const conflict = row.conflict || {};
  const reasons = [];
  if ((conflict.reasons || []).includes('multiple_references')) reasons.push('A mesma WP foi encontrada em mais de um chamado no campo Atividade DevOps.');
  if ((conflict.reasons || []).includes('linked_elsewhere')) reasons.push('A WP já possui um vínculo local do plugin com outro chamado.');
  const references = (conflict.source_references || []).map(legacyTicketLink);
  if (Number(conflict.hidden_references || 0) > 0) references.push(Number(conflict.hidden_references) + ' chamado(s) adicional(is) sem acesso no escopo atual');
  const existing = conflict.existing_link?.visible ? legacyTicketLink(conflict.existing_link) : 'Nenhum vínculo local acessível foi encontrado.';
  let transfer = '';
  if (conflict.can_transfer) {
    transfer = '<hr><div class="alert alert-warning mb-0"><strong>Transferência local disponível.</strong> A ação moverá somente o vínculo do plugin de ' + existing + ' para <a href="/front/ticket.form.php?id=' + Number(row.ticket_id) + '" target="_blank" rel="noopener">#' + Number(row.ticket_id) + '</a>. A WP não será alterada no OpenProject.</div><form method="post" action="/plugins/demandas/front/legacy-reconciliation.form.php" class="mt-3"><input type="hidden" name="_glpi_csrf_token" value="' + legacyEscape(legacyConflictConfig.csrf) + '"><input type="hidden" name="action" value="transfer"><input type="hidden" name="pair" value="' + Number(row.ticket_id) + ':' + Number(row.wp_id) + '"><input type="hidden" name="ticket_scope" value="' + legacyEscape(legacyConflictConfig.scope) + '"><label class="form-check"><input class="form-check-input" type="checkbox" name="confirm_transfer" value="1" required><span class="form-check-label">Confirmo que o chamado selecionado é o vínculo local correto para esta WP.</span></label><button class="btn btn-danger mt-3">Transferir vínculo local</button></form>';
  } else {
    const blockers = [];
    if ((conflict.reasons || []).includes('multiple_references')) blockers.push('corrija primeiro as referências duplicadas no campo Atividade DevOps');
    if (Number(conflict.time_entries || 0) > 0) blockers.push('existem entradas de tempo vinculadas ao chamado atual');
    if (conflict.has_public_data) blockers.push('o vínculo atual contém fase ou acompanhamento público');
    if (blockers.length === 0) blockers.push('você não possui permissão ou alteração nos dois chamados envolvidos');
    transfer = '<div class="alert alert-secondary mt-3 mb-0"><strong>Transferência bloqueada.</strong> Para corrigir: ' + legacyEscape(blockers.join('; ')) + '.</div>';
  }
  const wpUrl = legacyConflictConfig.external_url + '/work_packages/' + Number(row.wp_id);
  const items = references.map(reference => '<li>' + reference + '</li>').join('') || '<li>Nenhuma referência adicional visível.</li>';
  legacyConflictContent.innerHTML = '<p><strong>WP indicada:</strong> <a href="' + legacyEscape(wpUrl) + '" target="_blank" rel="noopener">#' + Number(row.wp_id) + '</a></p><p><strong>Chamado em análise:</strong> <a href="/front/ticket.form.php?id=' + Number(row.ticket_id) + '" target="_blank" rel="noopener">#' + Number(row.ticket_id) + ' — ' + legacyEscape(row.ticket_name) + '</a></p><h3 class="h5">Motivos</h3><ul>' + reasons.map(reason => '<li>' + legacyEscape(reason) + '</li>').join('') + '</ul><h3 class="h5">Referências encontradas</h3><ul>' + items + '</ul><h3 class="h5">Vínculo local atual</h3><p>' + existing + '</p>' + transfer;
}));
</script>
JS;
echo str_replace('__CONFIG__', $modalConfig, $modalScript);
echo '</div>';
Html::footer();
