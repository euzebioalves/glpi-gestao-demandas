<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\LegacyReconciliationService;
use GlpiPlugin\Demandas\ManagementDashboard;
use GlpiPlugin\Demandas\WorkPackageMonitoringList;

LegacyReconciliationService::checkAccess();
$all = ($_GET['ticket_scope'] ?? '') === 'all';
$ticketId = is_scalar($_GET['ticket'] ?? null) ? max(0, (int) $_GET['ticket']) : 0;
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        echo '<tr><td>' . ($row['state'] === 'ready' ? "<input class='form-check-input' type='checkbox' name='pairs[]' value='{$tid}:{$wp}' aria-label='Conciliar chamado {$tid} com WP {$wp}'>" : '—') . "</td><td><a href='/front/ticket.form.php?id={$tid}' target='_blank' rel='noopener'>#{$tid} — {$h($row['ticket_name'])}</a></td><td>" . ($wp > 0 ? '#' . $wp : '—') . "</td><td>{$h($labels[$row['state']])}</td></tr>";
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
echo '</div>';
Html::footer();
