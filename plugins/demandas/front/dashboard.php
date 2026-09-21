<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\ManagementDashboard;
use GlpiPlugin\Demandas\ManagementDashboardService;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\Config as DemandasConfig;

DemandasProfile::checkRight(DemandasProfile::VIEW_DASHBOARD);
$result = (new ManagementDashboardService())->result($_GET);
$filters = $result['filters'];
$summary = $result['summary'];
$rows = $result['rows'];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$pages = max(1, (int) ceil(count($rows) / $perPage));
$page = min($page, $pages);
$visibleRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

Html::header(ManagementDashboard::getTypeName(), $_SERVER['PHP_SELF'], 'management', ManagementDashboard::class);

function demandasH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function demandasQuery(array $filters, array $changes = []): string
{
    $query = array_filter(array_merge($filters, $changes), static fn(mixed $value): bool => $value !== '' && $value !== null);
    unset($query['page']);
    return http_build_query($query);
}

function demandasSelect(string $name, string $label, array $options, string $selected): void
{
    echo "<div class='col-sm-6 col-lg-3 col-xxl'><label class='form-label' for='demandas-{$name}'>" . demandasH($label) . "</label><select class='form-select' id='demandas-{$name}' name='{$name}'><option value=''>Todos</option>";
    foreach ($options as $value => $optionLabel) {
        $isSelected = (string) $value === $selected ? ' selected' : '';
        echo "<option value='" . demandasH($value) . "'{$isSelected}>" . demandasH($optionLabel) . '</option>';
    }
    echo '</select></div>';
}

function demandasPercent(int|float $value, int|float $total): float
{
    return $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
}

function demandasBarList(array $items, string $field, array $filters, int $limit = 8): void
{
    $visible = array_slice($items, 0, $limit);
    $maximum = max(1, ...array_map(static fn(array $item): int => (int) ($item['count'] ?? 0), $visible ?: [['count' => 1]]));
    foreach ($visible as $item) {
        $count = (int) ($item['count'] ?? 0);
        $width = max($count > 0 ? 4 : 0, round(($count / $maximum) * 100, 1));
        $url = '/plugins/demandas/front/dashboard.php?' . demandasQuery($filters, [$field => $item['value'], 'metric' => '']);
        echo "<a class='demandas-bar-row' href='" . demandasH($url) . "'><span class='demandas-bar-label' title='" . demandasH($item['label']) . "'>" . demandasH($item['label']) . "</span><span class='demandas-bar-track'><span class='demandas-bar-fill' style='width:{$width}%'></span></span><strong>{$count}</strong></a>";
    }
    if ($visible === []) echo "<div class='demandas-empty'>Nenhum dado para os filtros selecionados.</div>";
}

$total = (int) $summary['total'];
$coverage = demandasPercent((int) $summary['with_wp'], $total);
$riskTotal = (int) $summary['undocumented_improvement_bug'] + (int) $summary['older_30'] + (int) $summary['stale'];
$activeFilterCount = count(array_filter($filters, static fn(mixed $value): bool => $value !== '' && $value !== null));
$dashboardUpdatedAt = (new DateTimeImmutable())->format('d/m/Y H:i');

echo <<<'CSS'
<style>
.demandas-exec{--dm-navy:#14213d;--dm-blue:#246bfd;--dm-cyan:#2ec5ce;--dm-orange:#ff9f43;--dm-red:#ef476f;--dm-purple:#7b61ff;--dm-green:#16a085;--dm-surface:var(--tblr-bg-surface,#fff);--dm-border:rgba(98,105,118,.16);color:var(--tblr-body-color)}
.demandas-exec *{box-sizing:border-box}.demandas-hero{position:relative;overflow:hidden;border-radius:20px;padding:26px 28px;color:#fff;background:linear-gradient(120deg,#111c34 0%,#203d73 58%,#2b6ef3 100%);box-shadow:0 18px 45px rgba(20,33,61,.2)}
.demandas-hero:after{content:"";position:absolute;right:-80px;top:-120px;width:340px;height:340px;border-radius:50%;background:rgba(255,255,255,.08)}.demandas-hero>*{position:relative;z-index:1}.demandas-eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;opacity:.72}.demandas-hero h1{font-size:1.75rem;margin:.35rem 0}.demandas-hero p{max-width:720px;opacity:.8;margin:0}.demandas-hero-meta{display:flex;align-items:center;gap:12px;font-size:.78rem;opacity:.82}.demandas-live{display:inline-flex;align-items:center;gap:6px}.demandas-live:before{content:"";width:8px;height:8px;border-radius:50%;background:#5ee6a8;box-shadow:0 0 0 5px rgba(94,230,168,.14)}
.demandas-filter-card,.demandas-panel,.demandas-table-card{background:var(--dm-surface);border:1px solid var(--dm-border);border-radius:16px;box-shadow:0 8px 30px rgba(20,33,61,.055)}.demandas-filter-card{margin-top:16px;padding:18px}.demandas-filter-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.demandas-filter-title h2{font-size:.92rem;margin:0}.demandas-filter-count{font-size:.72rem;padding:4px 9px;border-radius:30px;background:rgba(36,107,253,.1);color:var(--dm-blue);font-weight:700}
.demandas-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin:16px 0}.demandas-kpi{position:relative;min-height:132px;border-radius:16px;padding:17px;color:#fff;text-decoration:none!important;overflow:hidden;box-shadow:0 10px 24px rgba(20,33,61,.13);transition:transform .18s ease,box-shadow .18s ease}.demandas-kpi:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(20,33,61,.2);color:#fff}.demandas-kpi:after{content:"";position:absolute;right:-22px;bottom:-35px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.12)}.demandas-kpi-icon{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.16);font-size:1.15rem}.demandas-kpi-value{font-size:1.8rem;line-height:1;font-weight:800;margin:15px 0 7px}.demandas-kpi-label{font-size:.76rem;line-height:1.25;opacity:.9}.demandas-kpi small{display:block;margin-top:7px;font-size:.66rem;opacity:.7}.dm-blue{background:linear-gradient(145deg,#246bfd,#1849b9)}.dm-green{background:linear-gradient(145deg,#20b486,#08795a)}.dm-orange{background:linear-gradient(145deg,#ffb347,#f07821)}.dm-red{background:linear-gradient(145deg,#ff6577,#d92f55)}.dm-purple{background:linear-gradient(145deg,#8c6cff,#5938c8)}.dm-slate{background:linear-gradient(145deg,#53657d,#2f3d52)}
.demandas-main-grid{display:grid;grid-template-columns:minmax(300px,.78fr) minmax(0,1.22fr) minmax(0,1.22fr);gap:16px;margin-bottom:16px}.demandas-panel{padding:20px;min-width:0}.demandas-panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:18px}.demandas-panel-title{font-size:.94rem;font-weight:700;margin:0}.demandas-panel-subtitle{font-size:.72rem;color:var(--tblr-secondary-color);margin-top:3px}.demandas-panel-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:rgba(36,107,253,.1);color:var(--dm-blue)}
.demandas-coverage{display:flex;align-items:center;justify-content:center;gap:22px;min-height:210px}.demandas-donut{--percent:0;position:relative;width:142px;height:142px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--dm-blue) calc(var(--percent)*1%),rgba(125,135,150,.16) 0)}.demandas-donut:before{content:"";position:absolute;inset:15px;border-radius:50%;background:var(--dm-surface)}.demandas-donut-value{position:relative;text-align:center;font-size:1.55rem;font-weight:800}.demandas-donut-value small{display:block;font-size:.64rem;color:var(--tblr-secondary-color);font-weight:600}.demandas-legend{display:grid;gap:12px;min-width:120px}.demandas-legend-item{display:grid;grid-template-columns:9px 1fr auto;gap:8px;align-items:center;font-size:.75rem}.demandas-legend-dot{width:9px;height:9px;border-radius:3px}.demandas-legend-item strong{font-size:.9rem}
.demandas-bar-list{display:grid;gap:12px}.demandas-bar-row{display:grid;grid-template-columns:minmax(90px,1.35fr) minmax(70px,2fr) 34px;gap:10px;align-items:center;color:inherit;text-decoration:none!important;font-size:.75rem}.demandas-bar-row:hover .demandas-bar-fill{filter:brightness(1.12)}.demandas-bar-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.demandas-bar-track{display:block;height:9px;border-radius:20px;background:rgba(125,135,150,.14);overflow:hidden}.demandas-bar-fill{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--dm-purple),var(--dm-blue));transition:width .25s}.demandas-bar-row strong{text-align:right}.demandas-empty{padding:28px 0;text-align:center;color:var(--tblr-secondary-color);font-size:.78rem}
.demandas-classification-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.demandas-class-item{padding:13px;border-radius:12px;background:rgba(125,135,150,.075);color:inherit;text-decoration:none!important;border:1px solid transparent;transition:border-color .18s}.demandas-class-item:hover{border-color:rgba(36,107,253,.3)}.demandas-class-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.demandas-class-top span{font-size:.73rem}.demandas-class-top strong{font-size:1.05rem}.demandas-mini-track{height:5px;border-radius:8px;background:rgba(125,135,150,.15);margin-top:9px;overflow:hidden}.demandas-mini-fill{display:block;height:100%;border-radius:inherit}.fill-red{background:var(--dm-red)}.fill-orange{background:var(--dm-orange)}.fill-cyan{background:var(--dm-cyan)}.fill-purple{background:var(--dm-purple)}
.demandas-secondary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.demandas-secondary-grid .demandas-panel{min-height:275px}.demandas-panel-footer{margin-top:16px;padding-top:13px;border-top:1px solid var(--dm-border);font-size:.7rem;color:var(--tblr-secondary-color)}
.demandas-table-card{overflow:hidden}.demandas-table-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--dm-border)}.demandas-table-head h2{font-size:.95rem;margin:0}.demandas-result-badge{padding:5px 10px;border-radius:20px;background:rgba(36,107,253,.1);color:var(--dm-blue);font-size:.72rem;font-weight:700}.demandas-table-card table{font-size:.76rem}.demandas-table-card thead th{font-size:.64rem;text-transform:uppercase;letter-spacing:.05em;color:var(--tblr-secondary-color);white-space:nowrap}.demandas-status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:20px;background:rgba(125,135,150,.1);white-space:nowrap}.demandas-status-pill:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--dm-blue)}
@media(max-width:1399px){.demandas-kpi-grid{grid-template-columns:repeat(3,1fr)}.demandas-main-grid{grid-template-columns:1fr 1fr}.demandas-main-grid .demandas-panel:last-child{grid-column:1/-1}}
@media(max-width:991px){.demandas-main-grid,.demandas-secondary-grid{grid-template-columns:1fr}.demandas-main-grid .demandas-panel:last-child{grid-column:auto}.demandas-hero{padding:22px}.demandas-hero-actions{margin-top:18px}}
@media(max-width:575px){.demandas-exec{padding-left:10px!important;padding-right:10px!important}.demandas-kpi-grid{grid-template-columns:repeat(2,1fr)}.demandas-kpi{min-height:122px}.demandas-coverage{flex-direction:column}.demandas-classification-grid{grid-template-columns:1fr}.demandas-bar-row{grid-template-columns:minmax(78px,1.2fr) minmax(60px,1.5fr) 30px}}
</style>
CSS;

echo "<div class='container-fluid px-4 pb-4 demandas-exec'>";
echo "<section class='demandas-hero'><div class='row align-items-center'><div class='col-lg'><div class='demandas-eyebrow'>Central de inteligência operacional</div><h1><i class='" . demandasH(ManagementDashboard::getIcon()) . " me-2'></i>" . demandasH(ManagementDashboard::getTypeName()) . "</h1><p>Acompanhe cobertura de documentação, riscos operacionais, evolução e concentração das demandas em uma única visão executiva.</p></div><div class='col-lg-auto demandas-hero-actions'><div class='demandas-hero-meta justify-content-lg-end mb-3'><span class='demandas-live'>Dados atualizados</span><span>{$dashboardUpdatedAt}</span></div>";
if (DemandasProfile::has(DemandasProfile::EXPORT_DASHBOARD)) {
    $exportQuery = demandasQuery($filters);
    echo "<div class='btn-group'><a class='btn btn-light' href='/plugins/demandas/front/dashboard-export.php?format=pdf&amp;" . demandasH($exportQuery) . "'><i class='ti ti-file-type-pdf me-1'></i>PDF</a><a class='btn btn-light' href='/plugins/demandas/front/dashboard-export.php?format=xlsx&amp;" . demandasH($exportQuery) . "'><i class='ti ti-file-spreadsheet me-1'></i>Excel</a></div>";
}
echo '</div></div></section>';

echo "<section class='demandas-filter-card'><div class='demandas-filter-title'><h2><i class='ti ti-adjustments-horizontal me-2 text-primary'></i>Filtros estratégicos</h2><span class='demandas-filter-count'>{$activeFilterCount} filtro(s) ativo(s)</span></div><form method='get' class='row g-3'>";
echo "<div class='col-sm-6 col-lg-3 col-xxl'><label class='form-label'>Busca</label><input class='form-control' name='q' value='" . demandasH($filters['q']) . "' placeholder='Número ou título'></div><div class='col-sm-6 col-lg-3 col-xxl'><label class='form-label'>Abertos desde</label><input class='form-control' type='date' name='date_from' value='" . demandasH($filters['date_from']) . "'></div><div class='col-sm-6 col-lg-3 col-xxl'><label class='form-label'>Abertos até</label><input class='form-control' type='date' name='date_to' value='" . demandasH($filters['date_to']) . "'></div>";
demandasSelect('has_wp', 'Vínculo', ['yes' => 'Com WP', 'no' => 'Sem WP'], $filters['has_wp']);
demandasSelect('age_bucket', 'Idade', ManagementDashboardService::AGE_BUCKETS, $filters['age_bucket']);
demandasSelect('glpi_status', 'Status GLPI', $result['options']['glpi_status'], $filters['glpi_status']);
demandasSelect('op_status', 'Status WP', $result['options']['op_status'], $filters['op_status']);
demandasSelect('public_phase', DemandasConfig::label('public_phase'), $result['options']['public_phase'], $filters['public_phase']);
demandasSelect('project', 'Projeto', $result['options']['project'], $filters['project']);
demandasSelect('type', 'Tipo da WP', $result['options']['type'], $filters['type']);
demandasSelect('client', 'Cliente', $result['options']['client'], $filters['client']);
echo "<div class='col-12 d-flex gap-2 justify-content-end'><a class='btn btn-outline-secondary' href='/plugins/demandas/front/dashboard.php'>Limpar</a><button class='btn btn-primary px-4' type='submit'><i class='ti ti-filter me-1'></i>Aplicar filtros</button></div></form></section>";

$kpis = [
    ['metric' => 'all', 'label' => 'Chamados no escopo', 'value' => $total, 'hint' => 'Volume analisado', 'icon' => 'ti ti-ticket', 'class' => 'dm-blue'],
    ['metric' => 'with_wp', 'label' => 'Com Work Package', 'value' => (int) $summary['with_wp'], 'hint' => $coverage . '% de cobertura', 'icon' => 'ti ti-link', 'class' => 'dm-green'],
    ['metric' => 'undocumented_improvement_bug', 'label' => 'Melhorias e bugs sem WP', 'value' => (int) $summary['undocumented_improvement_bug'], 'hint' => 'Exigem documentação', 'icon' => 'ti ti-file-alert', 'class' => 'dm-red'],
    ['metric' => 'older_30', 'label' => 'Acima de 30 dias', 'value' => (int) $summary['older_30'], 'hint' => 'Atenção ao envelhecimento', 'icon' => 'ti ti-clock-exclamation', 'class' => 'dm-orange'],
    ['metric' => 'stale', 'label' => 'Sem sincronizar há 7 dias', 'value' => (int) $summary['stale'], 'hint' => 'Verificar integração', 'icon' => 'ti ti-plug-off', 'class' => 'dm-purple'],
    ['metric' => '', 'label' => 'Idade média', 'value' => number_format((float) $summary['average_age'], 1, ',', '.') . 'd', 'hint' => 'Média dos chamados', 'icon' => 'ti ti-chart-arrows', 'class' => 'dm-slate'],
];
echo "<section class='demandas-kpi-grid' aria-label='Indicadores principais'>";
foreach ($kpis as $kpi) {
    $tag = $kpi['metric'] !== '' ? 'a' : 'div';
    $href = $kpi['metric'] !== '' ? " href='/plugins/demandas/front/dashboard.php?" . demandasH(demandasQuery($filters, ['metric' => $kpi['metric']])) . "'" : '';
    echo "<{$tag} class='demandas-kpi " . demandasH($kpi['class']) . "'{$href}><span class='demandas-kpi-icon'><i class='" . demandasH($kpi['icon']) . "'></i></span><div class='demandas-kpi-value'>" . demandasH($kpi['value']) . "</div><div class='demandas-kpi-label'>" . demandasH($kpi['label']) . "</div><small>" . demandasH($kpi['hint']) . "</small></{$tag}>";
}
echo '</section>';

echo "<section class='demandas-main-grid'><article class='demandas-panel'><div class='demandas-panel-head'><div><h2 class='demandas-panel-title'>Cobertura no OpenProject</h2><div class='demandas-panel-subtitle'>Chamados documentados em Work Packages</div></div><span class='demandas-panel-icon'><i class='ti ti-chart-donut-3'></i></span></div><div class='demandas-coverage'><div class='demandas-donut' style='--percent:{$coverage}'><div class='demandas-donut-value'>" . number_format($coverage, 1, ',', '.') . "%<small>cobertura</small></div></div><div class='demandas-legend'><div class='demandas-legend-item'><span class='demandas-legend-dot' style='background:var(--dm-blue)'></span><span>Com WP</span><strong>" . (int) $summary['with_wp'] . "</strong></div><div class='demandas-legend-item'><span class='demandas-legend-dot' style='background:rgba(125,135,150,.35)'></span><span>Sem WP</span><strong>" . (int) $summary['without_wp'] . "</strong></div></div></div><div class='demandas-panel-footer'><i class='ti ti-info-circle me-1'></i>Clique nos indicadores para acessar os chamados correspondentes.</div></article>";

$classItems = [
    ['metric' => 'open_bug', 'label' => 'Bugs abertos', 'value' => (int) $summary['open_bug'], 'fill' => 'fill-red'],
    ['metric' => 'open_improvement', 'label' => 'Melhorias abertas', 'value' => (int) $summary['open_improvement'], 'fill' => 'fill-orange'],
    ['metric' => 'open_collector', 'label' => 'Coletores abertos', 'value' => (int) $summary['open_collector'], 'fill' => 'fill-cyan'],
    ['metric' => 'suggestions', 'label' => 'Sugestões', 'value' => (int) $summary['suggestions'], 'fill' => 'fill-purple'],
];
$classMax = max(1, ...array_column($classItems, 'value'));
echo "<article class='demandas-panel'><div class='demandas-panel-head'><div><h2 class='demandas-panel-title'>Demandas por classificação</h2><div class='demandas-panel-subtitle'>Pendências que exigem acompanhamento</div></div><span class='demandas-panel-icon'><i class='ti ti-category-2'></i></span></div><div class='demandas-classification-grid'>";
foreach ($classItems as $item) {
    $width = max($item['value'] > 0 ? 5 : 0, round(($item['value'] / $classMax) * 100, 1));
    $url = '/plugins/demandas/front/dashboard.php?' . demandasQuery($filters, ['metric' => $item['metric']]);
    echo "<a class='demandas-class-item' href='" . demandasH($url) . "'><div class='demandas-class-top'><span>" . demandasH($item['label']) . "</span><strong>" . (int) $item['value'] . "</strong></div><div class='demandas-mini-track'><span class='demandas-mini-fill " . demandasH($item['fill']) . "' style='width:{$width}%'></span></div></a>";
}
echo "</div><div class='demandas-panel-footer'><strong>{$riskTotal}</strong> ocorrências somadas nos principais indicadores de atenção.</div></article>";

echo "<article class='demandas-panel'><div class='demandas-panel-head'><div><h2 class='demandas-panel-title'>Clientes com maior demanda</h2><div class='demandas-panel-subtitle'>Melhorias e bugs ainda abertos</div></div><span class='demandas-panel-icon'><i class='ti ti-building-community'></i></span></div><div class='demandas-bar-list'>";
$topClients = $summary['top_clients'];
$clientMax = max(1, ...array_map(static fn(array $client): int => (int) $client['count'], $topClients ?: [['count' => 1]]));
foreach ($topClients as $client) {
    $width = max((int) $client['count'] > 0 ? 4 : 0, round(((int) $client['count'] / $clientMax) * 100, 1));
    $url = '/plugins/demandas/front/dashboard.php?' . demandasQuery($filters, ['client' => $client['value'], 'metric' => 'open_improvement_bug']);
    echo "<a class='demandas-bar-row' href='" . demandasH($url) . "'><span class='demandas-bar-label' title='" . demandasH($client['label']) . "'>" . demandasH($client['label']) . "</span><span class='demandas-bar-track'><span class='demandas-bar-fill' style='width:{$width}%'></span></span><strong>" . (int) $client['count'] . "</strong></a>";
}
if ($topClients === []) echo "<div class='demandas-empty'>Nenhuma melhoria ou bug aberto.</div>";
echo "</div><div class='demandas-panel-footer'>Ranking limitado aos cinco clientes com maior volume.</div></article></section>";

$secondaryPanels = [
    ['field' => 'age_bucket', 'title' => 'Envelhecimento dos chamados', 'subtitle' => 'Distribuição por tempo desde a abertura', 'icon' => 'ti ti-hourglass'],
    ['field' => 'glpi_status', 'title' => 'Status no GLPI', 'subtitle' => 'Situação atual dos chamados', 'icon' => 'ti ti-progress-check'],
    ['field' => 'op_status', 'title' => 'Status das Work Packages', 'subtitle' => 'Etapa técnica no OpenProject', 'icon' => 'ti ti-brand-openproject'],
    ['field' => 'public_phase', 'title' => DemandasConfig::label('public_phase'), 'subtitle' => 'Visão comunicada ao solicitante', 'icon' => 'ti ti-message-circle-check'],
    ['field' => 'project', 'title' => 'Projetos com maior volume', 'subtitle' => 'Concentração das Work Packages', 'icon' => 'ti ti-folders'],
    ['field' => 'type', 'title' => 'Tipos de Work Package', 'subtitle' => 'Distribuição das entregas técnicas', 'icon' => 'ti ti-box-multiple'],
];
echo "<section class='demandas-secondary-grid'>";
foreach ($secondaryPanels as $panel) {
    echo "<article class='demandas-panel'><div class='demandas-panel-head'><div><h2 class='demandas-panel-title'>" . demandasH($panel['title']) . "</h2><div class='demandas-panel-subtitle'>" . demandasH($panel['subtitle']) . "</div></div><span class='demandas-panel-icon'><i class='" . demandasH($panel['icon']) . "'></i></span></div><div class='demandas-bar-list'>";
    demandasBarList($result['breakdowns'][$panel['field']], $panel['field'], $filters);
    echo '</div></article>';
}
echo '</section>';

echo "<section class='demandas-table-card'><div class='demandas-table-head'><div><h2>Chamados correspondentes</h2><div class='demandas-panel-subtitle'>Detalhamento para análise e tomada de decisão</div></div><span class='demandas-result-badge'>" . count($rows) . " resultado(s)</span></div><div class='table-responsive'><table class='table table-hover table-vcenter mb-0'><thead><tr><th>Chamado</th><th>Cliente</th><th>Classificação</th><th>Status GLPI</th><th>Idade</th><th>WP</th><th>Projeto</th><th>Tipo</th><th>Status WP</th><th>" . demandasH(DemandasConfig::label('public_phase')) . "</th><th>Última sincronização</th></tr></thead><tbody>";
foreach ($visibleRows as $row) {
    $ticketUrl = '/front/ticket.form.php?id=' . (int) $row['ticket_id'];
    $wp = $row['has_wp'] ? '#' . (int) $row['openproject_work_package_id'] : '—';
    echo '<tr><td><a class="fw-bold" href="' . demandasH($ticketUrl) . '">#' . (int) $row['ticket_id'] . '</a><div class="text-muted text-truncate" style="max-width:300px">' . demandasH($row['ticket_name']) . '</div></td><td>' . demandasH($row['entity_name'] ?: 'Entidade raiz') . '</td><td>' . demandasH($row['classification_label']) . '</td><td><span class="demandas-status-pill">' . demandasH($row['glpi_status_name']) . '</span></td><td>' . (int) $row['age_days'] . ' dia(s)</td><td>' . demandasH($wp) . '</td><td>' . demandasH($row['openproject_project_name'] ?: '—') . '</td><td>' . demandasH($row['openproject_type_name'] ?: '—') . '</td><td>' . demandasH($row['openproject_status'] ?: '—') . '</td><td>' . demandasH($row['public_phase'] ?: '—') . '</td><td>' . demandasH($row['last_synced_at'] ?: '—') . '</td></tr>';
}
if ($visibleRows === []) echo "<tr><td colspan='11' class='text-center text-muted py-5'>Nenhum chamado corresponde aos filtros selecionados.</td></tr>";
echo '</tbody></table></div>';
if ($pages > 1) {
    echo "<div class='p-3 d-flex justify-content-between align-items-center border-top'><span class='text-muted small'>Página {$page} de {$pages}</span><div class='btn-group'>";
    if ($page > 1) echo "<a class='btn btn-outline-secondary' href='?" . demandasH(demandasQuery($filters)) . "&amp;page=" . ($page - 1) . "'>Anterior</a>";
    if ($page < $pages) echo "<a class='btn btn-outline-secondary' href='?" . demandasH(demandasQuery($filters)) . "&amp;page=" . ($page + 1) . "'>Próxima</a>";
    echo '</div></div>';
}
echo '</section></div>';
Html::footer();
