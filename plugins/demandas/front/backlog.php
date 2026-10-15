<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\BacklogHub;
use GlpiPlugin\Demandas\Profile;
use GlpiPlugin\Demandas\Config;

BacklogHub::checkAccess();
Html::header('Backlog', $_SERVER['PHP_SELF'], 'management', BacklogHub::class);
$base = rtrim((string) Config::get('openproject_external_url', ''), '/');
if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)) $base = '';
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<link rel="stylesheet" href="/plugins/demandas/css/backlog.css">
<div id="demandas-backlog" data-openproject="<?= $escape($base) ?>">
  <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1><i class="ti ti-layout-kanban me-2" aria-hidden="true"></i>Backlog</h1>
      <p class="text-muted mb-0">Work Packages sob sua responsabilidade • horas pessoais registradas no OpenProject</p></div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if (Profile::has(Profile::CONFIGURE_BACKLOG)): ?>
      <button class="btn btn-outline-primary" id="backlog-configure" disabled aria-expanded="false" aria-controls="backlog-settings">Escolher colunas</button>
      <?php endif; ?>
      <button class="btn btn-primary" id="backlog-refresh">Atualizar quadro</button>
    </div>
  </header>
  <?php if (!Config::hasPersonalToken((int) Session::getLoginUserID())): ?>
  <div class="alert alert-info">Configure seu token pessoal em <a href="/front/preference.php?forcetab=GlpiPlugin%5CDemandas%5COpenProjectPersonalToken%241">Minhas configurações &gt; OpenProject</a>.</div>
  <?php endif; ?>
  <?php if (Profile::has(Profile::CONFIGURE_BACKLOG)): ?>
  <form id="backlog-settings" class="card card-body mb-3" hidden>
    <h2 class="h3">Minhas colunas</h2><p class="text-muted">A seleção será mantida nos próximos acessos. Atualizar o quadro não muda suas escolhas.</p>
    <input type="hidden" name="_glpi_csrf_token" value="<?= $escape(Session::getNewCSRFToken()) ?>">
    <div id="backlog-status-options"></div>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Salvar colunas</button></div>
  </form>
  <?php endif; ?>
  <div id="backlog-message" role="status" aria-live="polite"></div>
  <div id="backlog-board" aria-label="Work Packages por status" tabindex="0"></div>
</div>
<div class="offcanvas offcanvas-end" tabindex="-1" id="backlog-detail" aria-labelledby="backlog-detail-title">
  <div class="offcanvas-header"><h2 class="offcanvas-title" id="backlog-detail-title">Work Package</h2><button class="btn-close" type="button" data-bs-dismiss="offcanvas" aria-label="Fechar detalhes"></button></div>
  <div class="offcanvas-body"><h3 id="backlog-detail-subject"></h3><dl id="backlog-detail-fields"></dl><h4>Descrição</h4><div id="backlog-description"></div></div>
  <div class="p-3 border-top"><a id="backlog-openproject" class="btn btn-primary" target="_blank" rel="noopener noreferrer">Abrir no OpenProject <i class="ti ti-external-link ms-2" aria-hidden="true"></i></a></div>
</div>
<script src="/plugins/demandas/js/backlog.js" defer></script>
<?php Html::footer(); ?>
