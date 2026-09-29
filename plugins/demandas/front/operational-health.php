<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\OperationalHealthHub;
use GlpiPlugin\Demandas\OperationalHealthService;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
DemandasProfile::checkRight(DemandasProfile::VIEW_TECHNICAL);
DemandasProfile::checkRight(DemandasProfile::VIEW_OPERATIONAL_HEALTH);

$service = new OperationalHealthService();
$rows = $service->findings($_GET);
$summary = $service->summary($rows);
$latestActions = $service->latestActions(array_column($rows, 'id'));
$canManage = DemandasProfile::has(DemandasProfile::MANAGE_OPERATIONAL_HEALTH);
$rules = OperationalHealthService::rules();
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$query = static function (array $changes = []): string {
    $values = array_filter(array_merge($_GET, $changes), static fn(mixed $value): bool => $value !== '');
    return http_build_query($values);
};

Html::header('Pendências de Integração', $_SERVER['PHP_SELF'], 'management', OperationalHealthHub::class);
echo "<div class='container-xl'><div class='d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4'><div><h1 class='mb-1'>Pendências de integração</h1><p class='text-muted mb-0'>Verificação local e auditável dos vínculos já registrados entre chamados e Work Packages.</p></div>";
if ($canManage) {
    echo "<form method='post' action='/plugins/demandas/front/operational-health.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='action' value='run'><button class='btn btn-primary'><i class='ti ti-refresh me-1'></i>Executar verificação</button></form>";
}
echo '</div>';
if (!$canManage) {
    echo "<div class='alert alert-info'>Você possui acesso de consulta. A execução da verificação e o tratamento das pendências requerem a permissão específica de operação.</div>";
}
echo "<div class='row g-3 mb-4'>";
foreach ([['Pendências abertas', $summary['open'], 'primary', ['state' => 'open']], ['Reconhecidas', $summary['acknowledged'], 'warning', ['state' => 'acknowledged']], ['Ignoradas temporariamente', $summary['ignored'], 'secondary', ['state' => 'ignored']], ['Alta prioridade', $summary['high'], 'danger', ['severity' => 'high']]] as [$label, $value, $color, $changes]) {
    echo "<div class='col-sm-6 col-xl-3'><a class='card card-body text-decoration-none border-{$color}' href='?" . $h($query($changes)) . "'><span class='text-muted small'>{$h($label)}</span><strong class='fs-1 text-{$color}'>{$h($value)}</strong></a></div>";
}
echo '</div>';
echo "<form class='card card-body mb-4' method='get'><div class='row g-3 align-items-end'><div class='col-md-4'><label class='form-label' for='operational-health-state'>Situação</label><select class='form-select' id='operational-health-state' name='state'><option value=''>Todas</option>";
foreach (['open' => 'Aberta', 'acknowledged' => 'Reconhecida', 'ignored' => 'Ignorada temporariamente', 'resolved' => 'Resolvida'] as $value => $label) { $selected = (string) ($_GET['state'] ?? '') === $value ? ' selected' : ''; echo "<option value='{$h($value)}'{$selected}>{$h($label)}</option>"; }
echo "</select></div><div class='col-md-4'><label class='form-label' for='operational-health-rule-code'>Regra</label><select class='form-select' id='operational-health-rule-code' name='rule_code'><option value=''>Todas</option>";
foreach ($rules as $value => $label) { $selected = (string) ($_GET['rule_code'] ?? '') === $value ? ' selected' : ''; echo "<option value='{$h($value)}'{$selected}>{$h($label)}</option>"; }
echo "</select></div><div class='col-md-2'><label class='form-label' for='operational-health-severity'>Prioridade</label><select class='form-select' id='operational-health-severity' name='severity'><option value=''>Todas</option><option value='high'" . ((string) ($_GET['severity'] ?? '') === 'high' ? ' selected' : '') . ">Alta</option><option value='medium'" . ((string) ($_GET['severity'] ?? '') === 'medium' ? ' selected' : '') . ">Média</option></select></div><div class='col-md-2 d-flex gap-2'><button class='btn btn-primary flex-grow-1'><i class='ti ti-filter me-1'></i>Filtrar</button><a class='btn btn-outline-secondary' href='/plugins/demandas/front/operational-health.php' title='Limpar filtros'><i class='ti ti-x'></i></a></div></div></form>";
if ($rows === []) {
    echo "<div class='alert alert-secondary'>Nenhuma pendência encontrada para os filtros e entidades ativas. Execute uma verificação para atualizar o diagnóstico local.</div>";
} else {
    echo "<div class='card'><div class='table-responsive'><table class='table table-hover align-middle mb-0'><thead><tr><th>Prioridade</th><th>Regra</th><th>Chamado</th><th>Work Package</th><th>Evidência</th><th>Situação</th><th>Última tratativa</th><th>Última detecção</th>" . ($canManage ? '<th>Ações</th>' : '') . "</tr></thead><tbody>";
    foreach ($rows as $row) {
        $ticketId = (int) ($row['tickets_id'] ?? 0); $wpId = (int) ($row['openproject_work_package_id'] ?? 0); $evidence = (array) ($row['evidence'] ?? []);
        $ticketUrl = '/front/ticket.form.php?id=' . $ticketId;
        $wpUrl = rtrim((string) DemandasConfig::get('openproject_external_url', ''), '/') . '/work_packages/' . $wpId;
        $priority = (string) ($row['severity'] ?? 'medium');
        $latestAction = $latestActions[(int) ($row['id'] ?? 0)] ?? null;
        $actionLabels = ['acknowledge' => 'Reconhecida', 'ignore' => 'Ignorada por sete dias', 'reopen' => 'Reaberta'];
        $actionText = $latestAction === null ? 'Sem tratativa' : (($actionLabels[(string) ($latestAction['action'] ?? '')] ?? 'Atualizada') . ' em ' . (string) ($latestAction['date_creation'] ?? ''));
        echo "<tr><td><span class='badge text-bg-" . ($priority === 'high' ? 'danger' : 'warning') . "'>{$h($priority === 'high' ? 'Alta' : 'Média')}</span></td><td>{$h($rules[(string) ($row['rule_code'] ?? '')] ?? 'Regra desconhecida')}</td><td><a href='{$h($ticketUrl)}' target='_blank' rel='noopener'>#{$ticketId}<br><span class='small text-muted'>{$h($evidence['ticket_name'] ?? '')}</span></a></td><td>" . ($wpId > 0 ? "<a href='{$h($wpUrl)}' target='_blank' rel='noopener'>#{$wpId}<i class='ti ti-external-link ms-1'></i></a>" : '—') . "</td><td><span class='small'>{$h($evidence['wp_status'] ?? $evidence['reason'] ?? '')}</span></td><td>{$h(['open'=>'Aberta','acknowledged'=>'Reconhecida','ignored'=>'Ignorada','resolved'=>'Resolvida'][(string) ($row['state'] ?? '')] ?? '')}</td><td><span class='small'>{$h($actionText)}</span></td><td>{$h($row['last_detected_at'] ?? '')}</td>";
        if ($canManage) { echo "<td><div class='d-flex gap-1'><form method='post' action='/plugins/demandas/front/operational-health.form.php'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><input type='hidden' name='finding_id' value='{$h($row['id'])}'><button class='btn btn-sm btn-outline-primary' name='action' value='acknowledge' title='Reconhecer'><i class='ti ti-check'></i></button><button class='btn btn-sm btn-outline-secondary' name='action' value='ignore' title='Ignorar por sete dias'><i class='ti ti-clock-pause'></i></button><button class='btn btn-sm btn-outline-warning' name='action' value='reopen' title='Reabrir'><i class='ti ti-refresh'></i></button></form></div></td>"; }
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
}
echo "<p class='form-hint mt-3'>Esta central não consulta nem altera o OpenProject. Ela usa apenas o último estado persistido no GLPI, preserva o histórico de tratativas e exige permissões explícitas para consulta e operação.</p></div>";
Html::footer();
