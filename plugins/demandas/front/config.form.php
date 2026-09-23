<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Config as DemandasConfig;
use GlpiPlugin\Demandas\OpenProjectClient;
use GlpiPlugin\Demandas\StatusMapper;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\ClassificationPolicy;
use GlpiPlugin\Demandas\FieldsClassificationProvider;
use GlpiPlugin\Demandas\WorkPackageTemplate;

Session::checkLoginUser();
$canManageConfiguration = DemandasConfig::canManageConfiguration();
$currentUserId = (int) Session::getLoginUserID();
$administrativeTabs = ['automation', 'classification', 'templates', 'tutorial'];
$isPersonalAction = isset($_POST['save_personal_token']) || isset($_POST['test_personal_token']);
// A negativa deve ocorrer antes do tratamento de erros e de qualquer escrita.
if (!$canManageConfiguration && (
    in_array((string) ($_GET['tab'] ?? ''), $administrativeTabs, true)
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isPersonalAction)
)) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($isPersonalAction) {
            DemandasConfig::savePersonalToken($currentUserId, (string) ($_POST['personal_openproject_api_token'] ?? ''));
            if (isset($_POST['test_personal_token'])) {
                OpenProjectClient::forCurrentUser()->testConnection();
                Session::addMessageAfterRedirect('Seu token foi salvo e a conexão com o OpenProject foi validada.', true, INFO);
            } else {
                Session::addMessageAfterRedirect('Seu token de acesso ao OpenProject foi salvo.', true, INFO);
            }
            Html::redirect('/plugins/demandas/front/config.form.php?tab=my-access');
        }

        $input = $_POST;
        $input['ticket_log_enabled'] = isset($_POST['ticket_log_enabled']) ? '1' : '0';
        $phases = [];
        foreach ((array) ($_POST['public_phases'] ?? []) as $index => $phaseInput) {
            $name = trim((string) ($phaseInput['name'] ?? ''));
            if ($name === '') continue;
            $id = trim((string) ($phaseInput['id'] ?? ''));
            if ($id === '') $id = 'phase_' . substr(hash('sha256', $name . ':' . $index), 0, 12);
            $phases[] = ['id' => preg_replace('/[^a-zA-Z0-9_-]/', '_', $id), 'name' => $name];
        }
        if ($phases === []) {
            throw new RuntimeException('Cadastre pelo menos uma fase pública.');
        }
        $phaseIds = array_column($phases, 'id');
        $input['public_phases_json'] = json_encode($phases, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $rules = [];
        if (array_key_exists('status_mapping', $_POST) && is_array($_POST['status_mapping'])) {
            $mapping = [];
            foreach ($_POST['status_mapping'] as $statusId => $phase) {
                $statusId = (int) $statusId;
                $phase = trim((string) $phase);
                if ($statusId > 0 && ($phase === '' || in_array($phase, $phaseIds, true))) {
                    $mapping[(string) $statusId] = $phase;
                    $rules[(string) $statusId] = [
                        'message' => trim((string) ($_POST['status_message'][$statusId] ?? '')),
                        'auto_followup' => isset($_POST['status_auto_followup'][$statusId]),
                        'private_followup' => isset($_POST['status_private_followup'][$statusId]),
                    ];
                }
            }
            $input['status_mapping_json'] = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $input['status_rules_json'] = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $sourceMode = (string) ($_POST['classification_mode'] ?? 'none');
        $source = ['mode' => in_array($sourceMode, ['none', 'fields_plugin', 'ticket_field', 'related_table'], true) ? $sourceMode : 'none'];
        if ($source['mode'] === 'ticket_field') {
            $source['field'] = trim((string) ($_POST['classification_ticket_field'] ?? ''));
        } elseif ($source['mode'] === 'fields_plugin') {
            $source['field_id'] = (int) ($_POST['classification_fields_plugin_field_id'] ?? 0);
        } elseif ($source['mode'] === 'related_table') {
            $source += [
                'table' => trim((string) ($_POST['classification_table'] ?? '')),
                'ticket_key' => trim((string) ($_POST['classification_ticket_key'] ?? 'tickets_id')),
                'value_field' => trim((string) ($_POST['classification_value_field'] ?? '')),
                'itemtype_field' => trim((string) ($_POST['classification_itemtype_field'] ?? '')),
            ];
        }
        ClassificationPolicy::validateSource($source);
        $input['classification_source_json'] = json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $classificationRules = [];
        foreach ((array) ($_POST['classification_rules'] ?? []) as $ruleInput) {
            $value = trim((string) ($ruleInput['value'] ?? ''));
            if ($value === '') continue;
            $rawTypes = $ruleInput['allowed_type_names'] ?? ($ruleInput['allowed_types'] ?? '');
            $typeNames = is_array($rawTypes)
                ? $rawTypes
                : (preg_split('/[,;\r\n]+/', (string) $rawTypes) ?: []);
            $classificationRules[] = [
                'value' => $value,
                'label' => trim((string) ($ruleInput['label'] ?? '')) ?: $value,
                'allowed_type_names' => array_values(array_unique(array_filter(array_map('trim', $typeNames)))),
            ];
        }
        $input['classification_rules_json'] = json_encode($classificationRules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (array_key_exists('work_package_templates', $_POST)) {
            $workPackageTemplates = [];
            foreach ((array) $_POST['work_package_templates'] as $typeName => $template) {
                $typeName = trim((string) $typeName);
                $template = str_replace(["\r\n", "\r"], "\n", (string) $template);
                if ($typeName === '' || trim($template) === '') continue;
                if (mb_strlen($template) > 50000) {
                    throw new RuntimeException('Cada template de Work Package deve possuir no máximo 50.000 caracteres.');
                }
                $workPackageTemplates[$typeName] = $template;
            }
            $input['work_package_templates_json'] = json_encode($workPackageTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        DemandasConfig::save($input);
        if (isset($_POST['test_connection'])) {
            OpenProjectClient::forAutomation()->testConnection();
            Session::addMessageAfterRedirect(
                'Conexão automática realizada com sucesso. As ações manuais usarão o token pessoal de cada usuário.',
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect('Configuração salva.', true, INFO);
        }
    } catch (Throwable $exception) {
        Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
    }

    if ($isPersonalAction) {
        Html::redirect('/plugins/demandas/front/config.form.php?tab=my-access');
    }
    $redirectTab = (string) ($_GET['tab'] ?? 'automation');
    if (!in_array($redirectTab, ['automation', 'classification', 'templates'], true)) {
        $redirectTab = 'automation';
    }
    Html::redirect('/plugins/demandas/front/config.form.php?tab=' . rawurlencode($redirectTab));
}

$config = $canManageConfiguration ? DemandasConfig::all() : [];
if ($canManageConfiguration) {
    $config['webhook_secret'] = DemandasConfig::webhookSecret();
}
$statuses = [];
$statusLoadError = null;
if ($canManageConfiguration && DemandasConfig::isAutomationReady()) {
    try {
        $statuses = OpenProjectClient::forAutomation()->getStatuses();
    } catch (Throwable $exception) {
        $statusLoadError = $exception->getMessage();
    }
}
$configuredMapping = StatusMapper::configuredMapping();
$configuredRules = StatusMapper::configuredRules();
$publicPhases = StatusMapper::phases();
$classificationSource = ClassificationPolicy::source();
$classificationRules = ClassificationPolicy::rules();
$fieldsPluginFields = FieldsClassificationProvider::fields();
$fieldsPluginOptions = [];
foreach (array_keys($fieldsPluginFields) as $fieldId) {
    try {
        $fieldsPluginOptions[(string) $fieldId] = FieldsClassificationProvider::options((int) $fieldId);
    } catch (Throwable) {
        $fieldsPluginOptions[(string) $fieldId] = [];
    }
}
$typeNames = [];
if ($canManageConfiguration && DemandasConfig::isAutomationReady()) {
    try {
        foreach (OpenProjectClient::forAutomation()->getTypes() as $type) {
            $name = trim((string) ($type['name'] ?? ''));
            if ($name !== '') $typeNames[$name] = $name;
        }
        ksort($typeNames, SORT_NATURAL | SORT_FLAG_CASE);
    } catch (Throwable) {
        // A configuração continua disponível mesmo se o OpenProject estiver temporariamente indisponível.
    }
}
$workPackageTemplates = WorkPackageTemplate::templates();

Html::header('Gestão de Demandas', $_SERVER['PHP_SELF'], 'config', 'plugins');

function demandasField(string $name, string $label, array $config, string $type = 'text'): void
{
    $value = htmlspecialchars((string) ($config[$name] ?? ''), ENT_QUOTES);
    echo "<div class='col-md-6'><label class='form-label' for='{$name}'>{$label}</label>";
    echo "<input class='form-control' id='{$name}' name='{$name}' type='{$type}' value='{$value}'></div>";
}

function demandasTabPaneAttributes(string $id, bool $isActive): string
{
    return " id='{$id}' class='tab-pane' role='tabpanel' style='display: " . ($isActive ? 'block' : 'none') . "'";
}

function demandasRenderConfigurationTutorial(): void
{
    echo <<<'HTML'
<div class="card">
  <div class="card-header"><h3 class="card-title">Tutorial de configuração</h3></div>
  <div class="card-body">
    <div class="alert alert-info">
      <strong>Quem configura:</strong> o perfil ativo com a permissão <strong>Administrar as configurações do plugin</strong>, independentemente do nome. O token automático é exclusivo da automação; cada pessoa que cria ou sincroniza Work Packages manualmente configura o próprio token na aba <strong>Meu acesso ao OpenProject</strong>.
    </div>
    <div class="row g-4">
      <div class="col-lg-7">
        <h3 class="h4">Configuração inicial</h3>
        <ol class="mb-0">
          <li class="mb-3"><strong>Prepare o usuário técnico no OpenProject.</strong> Crie um usuário de serviço com as permissões mínimas necessárias para consultar e atualizar Work Packages. Gere o token dele e não use uma conta pessoal ou a conta <code>admin</code>.</li>
          <li class="mb-3"><strong>Conecte o plugin.</strong> Na aba <strong>Integração e automação</strong>, informe a URL interna da API, a URL externa de navegação, a URL externa do GLPI e o token automático do bot. Clique em <strong>Salvar e testar conexão</strong>.</li>
          <li class="mb-3"><strong>Defina a comunicação com o cliente.</strong> Cadastre as fases públicas e, no <strong>De/Para de status</strong>, escolha a fase, a mensagem padrão, o envio automático e a privacidade para cada status técnico do OpenProject.</li>
          <li class="mb-3"><strong>Configure o webhook.</strong> No OpenProject, crie o webhook para <em>Work package atualizada</em>, usando a URL interna exibida nesta tela e o mesmo segredo de assinatura. Restrinja-o ao projeto necessário.</li>
          <li class="mb-3"><strong>Revise a classificação.</strong> Na aba <strong>Classificação</strong>, escolha a origem e libere os tipos de Work Package para cada classificação. Uma classificação sem regra não poderá criar WP.</li>
          <li class="mb-3"><strong>Preencha os templates.</strong> Na aba <strong>Templates</strong>, cadastre o Markdown de cada tipo de WP que poderá ser criado. O plugin bloqueia a criação quando o tipo não possui template.</li>
          <li class="mb-3"><strong>Oriente os operadores.</strong> Cada usuário que cria, sincroniza ou lança tempo manualmente deve abrir <strong>Minhas configurações &gt; OpenProject</strong> e informar o próprio token.</li>
          <li><strong>Configure os dias sem expediente.</strong> Em <strong>Gerência &gt; Horas e Ponto &gt; Administração do ponto</strong>, quem possui a permissão Administrar feriados e compensações cadastra feriados e dias não úteis. Eles são neutros e não geram crédito ou débito no banco de horas.</li>
        </ol>
      </div>
      <div class="col-lg-5">
        <div class="border rounded p-3 h-100">
          <h3 class="h4">Roteiro visual</h3>
          <p class="text-muted small">Siga as abas da esquerda para a direita. Elas foram organizadas para evitar rolagem entre configurações não relacionadas.</p>
          <div class="d-flex flex-column gap-2">
            <div class="p-2 border rounded"><strong>1. Integração e automação</strong><br><span class="text-muted small">URLs, token do bot, fases, webhook e status.</span></div>
            <div class="text-center text-muted"><i class="ti ti-arrow-down"></i></div>
            <div class="p-2 border rounded"><strong>2. Classificação</strong><br><span class="text-muted small">Origem e tipos permitidos por classificação.</span></div>
            <div class="text-center text-muted"><i class="ti ti-arrow-down"></i></div>
            <div class="p-2 border rounded"><strong>3. Templates</strong><br><span class="text-muted small">Descrição Markdown obrigatória por tipo de WP.</span></div>
            <div class="text-center text-muted"><i class="ti ti-arrow-down"></i></div>
            <div class="p-2 border rounded"><strong>4. Minhas configurações &gt; OpenProject</strong><br><span class="text-muted small">Token individual de quem executa ações manuais.</span></div>
            <div class="text-center text-muted"><i class="ti ti-arrow-down"></i></div>
            <div class="p-2 border rounded"><strong>5. Administração do ponto</strong><br><span class="text-muted small">Feriados e dias não úteis, com a permissão Administrar feriados e compensações.</span></div>
          </div>
        </div>
      </div>
    </div>
    <hr class="my-4">
    <h3 class="h4">Permissões administrativas</h3>
    <p>Em Administração &gt; Perfis &gt; Gestão de Demandas, conceda <strong>Administrar as configurações do plugin</strong> ao perfil desejado e selecione esse perfil na sessão. Nomes como Master ou Administrador não alteram os direitos. Sem essa permissão, somente o token pessoal fica disponível. Feriados e exceções de acesso usam permissões próprias.</p>
    <h3 class="h4">Atualização do plugin</h3>
    <p>Faça backup do banco e dos arquivos, substitua a pasta <code>plugins/demandas</code> pelo pacote novo e execute <strong>Atualizar</strong> em <strong>Configuração &gt; Plugins</strong>. Não desinstale para atualizar, pois isso apaga os dados do plugin. Na versão 0.18.3, mantenha o fuso horário do GLPI e confira os horários de ponto após a migração. Se a atualização indicar datas inválidas, solicite revisão ao administrador.</p>
    <h3 class="h4">Checklist de validação</h3>
    <ul class="mb-0">
      <li>o teste de conexão automática foi concluído sem erro;</li>
      <li>o webhook usa o segredo correto e o evento <em>Work package atualizada</em>;</li>
      <li>cada status relevante possui uma fase pública e, quando aplicável, uma mensagem revisada;</li>
      <li>os tipos liberados por classificação têm template configurado;</li>
      <li>o usuário técnico não é usado para criar Work Packages e cada operador possui token pessoal.</li>
      <li>os feriados e dias não úteis locais foram cadastrados antes da conferência do banco de horas.</li>
    </ul>
    <p class="form-hint mt-4 mb-0">Este tutorial é parte da configuração do plugin. Toda nova funcionalidade que altere o processo de configuração deve atualizar esta aba e o histórico do plugin.</p>
  </div>
</div>
HTML;
}

$activeTab = (string) ($_GET['tab'] ?? ($canManageConfiguration ? 'automation' : 'my-access'));
if (!$canManageConfiguration || !in_array($activeTab, ['automation', 'classification', 'templates', 'tutorial'], true)) {
    $activeTab = 'my-access';
}
echo <<<'HTML'
<style>
.demandas-status-mapping-table { min-width: 980px; table-layout: fixed; }
.demandas-status-mapping-table textarea { min-height: 5.75rem; resize: vertical; }
</style>
HTML;
echo "<div class='container-xl'><ul class='nav nav-tabs mb-4' role='tablist'>";
echo "<li class='nav-item'><a class='nav-link" . ($activeTab === 'my-access' ? ' active' : '') . "' href='?tab=my-access' role='tab'>Meu acesso ao OpenProject</a></li>";
if ($canManageConfiguration) {
    echo "<li class='nav-item'><a class='nav-link" . ($activeTab === 'automation' ? ' active' : '') . "' href='?tab=automation' role='tab'>Integração e automação</a></li>";
    echo "<li class='nav-item'><a class='nav-link" . ($activeTab === 'classification' ? ' active' : '') . "' href='?tab=classification' role='tab'>Classificação</a></li>";
    echo "<li class='nav-item'><a class='nav-link" . ($activeTab === 'templates' ? ' active' : '') . "' href='?tab=templates' role='tab'>Templates</a></li>";
    echo "<li class='nav-item'><a class='nav-link" . ($activeTab === 'tutorial' ? ' active' : '') . "' href='?tab=tutorial' role='tab'>Tutorial</a></li>";
}
echo "</ul><div class='tab-content'>";
echo '<div' . demandasTabPaneAttributes('demandas-my-access', $activeTab === 'my-access') . "><div class='card'><div class='card-header'><h3 class='card-title'>Meu acesso ao OpenProject</h3></div><div class='card-body'>";
echo "<p class='text-muted'>Este token é pessoal e será usado nas suas criações, sincronizações manuais e lançamentos de tempo. O token automático do plugin não é usado nessas ações.</p>";
echo "<form method='post' class='row g-3'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'>";
echo "<div class='col-md-8'><label class='form-label' for='personal_openproject_api_token'>Meu token da API</label><input class='form-control' id='personal_openproject_api_token' name='personal_openproject_api_token' type='password' autocomplete='new-password' placeholder='" . (DemandasConfig::hasPersonalToken($currentUserId) ? 'Token já configurado — informe outro valor para substituí-lo' : 'Informe o token de acesso do OpenProject') . "'><div class='form-hint'>O valor não é exibido novamente. Deixe em branco para manter o token atual.</div></div>";
echo "<div class='col-md-4 d-flex align-items-end gap-2'><button class='btn btn-primary' name='save_personal_token' value='1'>Salvar meu token</button><button class='btn btn-outline-primary' name='test_personal_token' value='1'>Salvar e testar</button></div></form>";
echo "</div></div></div>";

if ($canManageConfiguration) {
echo "<form method='post'><input type='hidden' name='_glpi_csrf_token' value='" . Session::getNewCSRFToken() . "'><div" . demandasTabPaneAttributes('demandas-automation', $activeTab === 'automation') . "><div class='card'><div class='card-header'><h3 class='card-title'>Integração com OpenProject</h3></div><div class='card-body'><div class='row g-3'>";
demandasField('openproject_internal_url', 'URL interna da API', $config);
demandasField('openproject_external_url', 'URL externa para navegação', $config);
demandasField('glpi_external_url', 'URL externa do GLPI', $config);
demandasField('request_timeout', 'Timeout em segundos', $config, 'number');
echo "<div class='col-md-6'><label class='form-label' for='openproject_automation_api_token'>Token automático do bot</label><input class='form-control' id='openproject_automation_api_token' name='openproject_automation_api_token' type='password' autocomplete='new-password' placeholder='" . (DemandasConfig::automationToken() !== '' ? 'Token já configurado — informe outro valor para substituí-lo' : 'Informe o token do usuário técnico') . "'><div class='form-hint'>Usado somente por webhook e sincronizações automáticas. O plugin bloqueia seu uso para criar Work Packages.</div></div>";
echo '</div>';

echo "<hr class='my-4'><h3 class='h4'>Nomenclaturas da interface</h3>";
echo "<p class='text-muted'>Personalize os nomes exibidos pelo plugin. A alteração do menu pode exigir uma nova autenticação para renovar o cache do GLPI.</p><div class='row g-3'>";
demandasField('label_public_phase', 'Nome de “Fase Pública”', $config);
demandasField('label_demand_evolution', 'Nome de “Evolução da Demanda”', $config);
demandasField('label_management_dashboard', 'Nome de “Visão Gerencial de Demandas”', $config);
echo '</div>';

$ticketLogEnabled = (string) ($config['ticket_log_enabled'] ?? '0') === '1' ? ' checked' : '';
echo "<hr class='my-4'><h3 class='h4'>Log organizado do chamado</h3>";
echo "<p class='text-muted'>Cria uma linha do tempo legível a partir do histórico nativo do GLPI, sem alterar ou excluir os registros originais.</p>";
echo "<div class='row g-3 align-items-end'>";
demandasField('label_ticket_log', 'Nome da aba de log', $config);
echo "<div class='col-md-6 pb-2'><label class='form-check form-switch'><input class='form-check-input' type='checkbox' name='ticket_log_enabled' value='1'{$ticketLogEnabled}><span class='form-check-label'>Ativar o log organizado nos chamados</span></label></div></div>";

echo "</div></div><div class='card mt-4'><div class='card-header'><h3 class='card-title'>Fases públicas e sincronização automática</h3></div><div class='card-body'>";
echo "<h3 class='h4'>Fases públicas</h3>";
echo "<p class='text-muted'>Cadastre os nomes apresentados ao cliente. Eles não dependem dos status técnicos do OpenProject.</p>";
echo "<div class='table-responsive'><table class='table table-sm align-middle' id='demandas-phases-table'><thead><tr><th>Nome da fase</th><th class='w-1'>Ação</th></tr></thead><tbody>";
$phaseIndex = 0;
foreach ($publicPhases as $phaseId => $phaseName) {
    echo "<tr><td><input type='hidden' name='public_phases[{$phaseIndex}][id]' value='" . htmlspecialchars($phaseId, ENT_QUOTES) . "'><input class='form-control' name='public_phases[{$phaseIndex}][name]' value='" . htmlspecialchars($phaseName, ENT_QUOTES) . "' required></td><td><button class='btn btn-outline-danger demandas-remove-phase' type='button' aria-label='Remover fase'><i class='ti ti-trash'></i></button></td></tr>";
    $phaseIndex++;
}
echo "</tbody></table></div><button class='btn btn-outline-secondary' id='demandas-add-phase' type='button'><i class='ti ti-plus'></i> Adicionar fase</button>";

$webhookUrl = rtrim((string) ($config['glpi_external_url'] ?? ''), '/') . '/plugins/demandas/webhook.php';
$internalWebhookUrl = 'http://glpi/plugins/demandas/webhook.php';
echo "<hr class='my-4'><h3 class='h4'>Sincronização automática</h3>";
echo "<p class='text-muted'>Cadastre o endereço interno e o mesmo segredo no webhook do OpenProject.</p>";
echo "<div class='row g-3'>";
echo "<div class='col-md-6'><label class='form-label'>URL do webhook na rede Docker</label><input class='form-control' type='text' readonly value='" . htmlspecialchars($internalWebhookUrl, ENT_QUOTES) . "'></div>";
echo "<div class='col-md-6'><label class='form-label'>URL externa para teste de saúde</label><input class='form-control' type='text' readonly value='" . htmlspecialchars($webhookUrl, ENT_QUOTES) . "'></div>";
$webhookSecret = htmlspecialchars((string) ($config['webhook_secret'] ?? ''), ENT_QUOTES);
echo "<div class='col-md-6'><label class='form-label' for='webhook_secret'>Segredo de assinatura do webhook</label>";
echo "<div class='input-group'>";
echo "<input class='form-control' id='webhook_secret' name='webhook_secret' type='password' value='{$webhookSecret}' autocomplete='new-password'>";
echo "<button class='btn btn-outline-secondary' id='demandas-toggle-secret' type='button' aria-controls='webhook_secret' aria-pressed='false'>Mostrar</button>";
echo "<button class='btn btn-outline-secondary' id='demandas-copy-secret' type='button'>Copiar</button>";
echo "</div><div class='form-hint' id='demandas-secret-feedback'>O segredo permanece oculto até você clicar em Mostrar.</div></div>";
echo "</div>";

echo "<hr class='my-4'><h3 class='h4'>De/Para de status</h3>";
echo "<p class='text-muted'>Defina a fase pública apresentada no GLPI para cada status técnico do OpenProject.</p>";
echo "<p class='form-hint'><strong>Privado desmarcado:</strong> o acompanhamento será visível ao cliente. <strong>Privado marcado:</strong> será exibido somente aos perfis com permissão para visualizar acompanhamentos privados.</p>";
echo "<p class='form-hint'>Variáveis: <code>{{ticket.id}}</code>, <code>{{ticket.name}}</code>, <code>{{requester.name}}</code>, <code>{{status.previous}}</code>, <code>{{status.current}}</code>, <code>{{phase.previous}}</code>, <code>{{phase.current}}</code>, <code>{{work_package.id}}</code> e <code>{{project.name}}</code>.</p>";
if ($statusLoadError !== null) {
    echo "<div class='alert alert-warning'>Não foi possível carregar os status: " . htmlspecialchars($statusLoadError) . '</div>';
} elseif ($statuses === []) {
    echo "<div class='alert alert-info'>Salve e teste a conexão para carregar os status do OpenProject.</div>";
} else {
    echo "<div class='table-responsive'><table class='table table-sm align-middle demandas-status-mapping-table'><colgroup><col style='width:4%'><col style='width:15%'><col style='width:25%'><col style='width:42%'><col style='width:7%'><col style='width:7%'></colgroup><thead><tr><th>ID</th><th>Status do OpenProject</th><th>" . htmlspecialchars(DemandasConfig::label('public_phase')) . "</th><th>Mensagem padrão</th><th>Automático</th><th>Privado</th></tr></thead><tbody>";
    foreach ($statuses as $status) {
        $statusId = (int) ($status['id'] ?? 0);
        $statusName = (string) ($status['name'] ?? $status['_links']['self']['title'] ?? ('Status #' . $statusId));
        if ($statusId <= 0) {
            continue;
        }
        $selectedPhase = array_key_exists((string) $statusId, $configuredMapping)
            ? (string) $configuredMapping[(string) $statusId]
            : StatusMapper::defaultPhase($statusName, '');
        echo '<tr><td>' . $statusId . '</td><td>' . htmlspecialchars($statusName) . '</td><td>';
        echo "<select class='form-select' name='status_mapping[{$statusId}]'>";
        echo "<option value=''" . ($selectedPhase === '' ? ' selected' : '') . ">Manter fase pública anterior</option>";
        foreach ($publicPhases as $phaseId => $phaseName) {
            $selected = ($selectedPhase === $phaseId || $selectedPhase === $phaseName) ? ' selected' : '';
            echo "<option value='" . htmlspecialchars($phaseId, ENT_QUOTES) . "'{$selected}>" . htmlspecialchars($phaseName) . '</option>';
        }
        $rule = is_array($configuredRules[(string) $statusId] ?? null) ? $configuredRules[(string) $statusId] : [];
        $message = htmlspecialchars((string) ($rule['message'] ?? ''), ENT_QUOTES);
        $auto = !empty($rule['auto_followup']) ? ' checked' : '';
        $private = !empty($rule['private_followup']) ? ' checked' : '';
        echo "</select></td><td><textarea class='form-control' rows='3' name='status_message[{$statusId}]' placeholder='Ex.: Chamado #{{ticket.id}} avançou para {{phase.current}}.'>{$message}</textarea></td>";
        echo "<td class='text-center'><input class='form-check-input' type='checkbox' name='status_auto_followup[{$statusId}]' value='1'{$auto}></td>";
        echo "<td class='text-center'><input class='form-check-input' type='checkbox' name='status_private_followup[{$statusId}]' value='1'{$private}></td></tr>";
    }
echo '</tbody></table></div>';
}

echo "</div></div></div><div" . demandasTabPaneAttributes('demandas-classification', $activeTab === 'classification') . "><div class='card'><div class='card-header'><h3 class='card-title'>Classificação do chamado e tipos de Work Package</h3></div><div class='card-body'>";
echo "<p class='text-muted'>Defina de onde vem a classificação e quais tipos do OpenProject são permitidos para cada valor. Uma classificação configurada sem regra correspondente não poderá criar WP.</p>";
$mode = (string) ($classificationSource['mode'] ?? 'none');
echo "<div class='row g-3'><div class='col-md-6'><label class='form-label' for='classification_mode'>Origem da classificação</label><select class='form-select' id='classification_mode' name='classification_mode'>";
foreach ([
    'none' => 'Não aplicar restrição',
    'fields_plugin' => 'Campo do plugin Fields (recomendado)',
    'ticket_field' => 'Avançado: campo da tabela principal do chamado',
    'related_table' => 'Avançado: campo em tabela relacionada',
] as $value => $label) {
    echo "<option value='{$value}'" . ($mode === $value ? ' selected' : '') . ">{$label}</option>";
}
echo "</select></div>";
$selectedFieldsPluginId = (int) ($classificationSource['field_id'] ?? 0);
echo "<div class='col-md-6 demandas-classification-source demandas-source-fields_plugin'><label class='form-label' for='classification_fields_plugin_field_id'>Campo de classificação</label>";
if ($fieldsPluginFields === []) {
    echo "<div class='alert alert-warning mb-0'>Nenhuma lista suspensa do plugin Fields vinculada a chamados foi encontrada.</div>";
} else {
    echo "<select class='form-select' id='classification_fields_plugin_field_id' name='classification_fields_plugin_field_id'><option value=''>Selecione o campo</option>";
    foreach ($fieldsPluginFields as $fieldId => $field) {
        $selected = $selectedFieldsPluginId === (int) $fieldId ? ' selected' : '';
        $fieldLabel = (string) $field['container_label'] . ' — ' . (string) $field['label'];
        echo "<option value='" . (int) $fieldId . "'{$selected}>" . htmlspecialchars($fieldLabel) . '</option>';
    }
    echo "</select><div class='form-hint'>O plugin localizará automaticamente a tabela, as colunas e as opções cadastradas no Fields.</div>";
}
echo '</div>';
$ticketField = htmlspecialchars((string) ($classificationSource['field'] ?? 'itilcategories_id'), ENT_QUOTES);
echo "<div class='col-md-6 demandas-classification-source demandas-source-ticket_field'><label class='form-label'>Nome técnico do campo</label><input class='form-control' name='classification_ticket_field' value='{$ticketField}' placeholder='Ex.: itilcategories_id'><div class='form-hint'>O nome deve existir nos dados do chamado (glpi_tickets).</div></div>";
$relatedFields = [
    'classification_table' => ['Tabela customizada', (string) ($classificationSource['table'] ?? ''), 'glpi_plugin_fields_...'],
    'classification_ticket_key' => ['Coluna de vínculo com o chamado', (string) ($classificationSource['ticket_key'] ?? 'tickets_id'), 'tickets_id ou items_id'],
    'classification_value_field' => ['Coluna que contém a classificação', (string) ($classificationSource['value_field'] ?? ''), 'nome_tecnico_do_campo'],
    'classification_itemtype_field' => ['Coluna itemtype (opcional)', (string) ($classificationSource['itemtype_field'] ?? ''), 'itemtype'],
];
foreach ($relatedFields as $fieldName => [$label, $value, $placeholder]) {
    echo "<div class='col-md-3 demandas-classification-source demandas-source-related_table'><label class='form-label'>{$label}</label><input class='form-control' name='{$fieldName}' value='" . htmlspecialchars($value, ENT_QUOTES) . "' placeholder='{$placeholder}'></div>";
}
echo '</div>';
if ($typeNames !== []) {
    echo "<p class='form-hint mt-3'>Tipos encontrados no OpenProject: " . htmlspecialchars(implode(', ', $typeNames)) . '.</p>';
}
echo "<div class='table-responsive mt-3' id='demandas-classification-rules-wrapper'><table class='table table-sm align-middle' id='demandas-classification-rules'><thead><tr><th>Classificação encontrada no GLPI</th><th>Tipos de WP permitidos</th><th class='w-1 demandas-manual-rule-column'>Ação</th></tr></thead><tbody>";
$classificationIndex = 0;
$rulesByValue = [];
foreach ($classificationRules as $rule) $rulesByValue[(string) ($rule['value'] ?? '')] = $rule;
if ($mode === 'fields_plugin' && $selectedFieldsPluginId > 0) {
    foreach ($fieldsPluginOptions[(string) $selectedFieldsPluginId] ?? [] as $valueRaw => $labelRaw) {
        $value = htmlspecialchars((string) $valueRaw, ENT_QUOTES);
        $label = htmlspecialchars((string) $labelRaw, ENT_QUOTES);
        $allowedNames = array_map('mb_strtolower', (array) ($rulesByValue[(string) $valueRaw]['allowed_type_names'] ?? []));
        echo "<tr><td><strong>{$label}</strong><div class='text-muted small'>Valor interno: {$value}</div><input type='hidden' name='classification_rules[{$classificationIndex}][value]' value='{$value}'><input type='hidden' name='classification_rules[{$classificationIndex}][label]' value='{$label}'></td><td><div class='d-flex flex-wrap gap-3'>";
        foreach ($typeNames as $typeName) {
            $escapedType = htmlspecialchars($typeName, ENT_QUOTES);
            $checked = in_array(mb_strtolower($typeName), $allowedNames, true) ? ' checked' : '';
            echo "<label class='form-check'><input class='form-check-input' type='checkbox' name='classification_rules[{$classificationIndex}][allowed_type_names][]' value='{$escapedType}'{$checked}><span class='form-check-label'>{$escapedType}</span></label>";
        }
        echo "</div></td><td class='demandas-manual-rule-column'></td></tr>";
        $classificationIndex++;
    }
} else {
    foreach ($classificationRules as $rule) {
        $value = htmlspecialchars((string) ($rule['value'] ?? ''), ENT_QUOTES);
        $label = htmlspecialchars((string) ($rule['label'] ?? ''), ENT_QUOTES);
        $allowed = htmlspecialchars(implode(', ', (array) ($rule['allowed_type_names'] ?? [])), ENT_QUOTES);
        echo "<tr><td><div class='row g-2'><div class='col-md-5'><input class='form-control' name='classification_rules[{$classificationIndex}][value]' value='{$value}' placeholder='Valor armazenado' required></div><div class='col-md-7'><input class='form-control' name='classification_rules[{$classificationIndex}][label]' value='{$label}' placeholder='Nome apresentado' required></div></div></td><td><input class='form-control' name='classification_rules[{$classificationIndex}][allowed_types]' value='{$allowed}' placeholder='Bug, User Story'></td><td><button class='btn btn-outline-danger demandas-remove-classification' type='button'><i class='ti ti-trash'></i></button></td></tr>";
        $classificationIndex++;
    }
}
echo "</tbody></table></div><button class='btn btn-outline-secondary' id='demandas-add-classification' type='button'><i class='ti ti-plus'></i> Adicionar regra manual</button>";

echo "</div></div></div><div" . demandasTabPaneAttributes('demandas-templates', $activeTab === 'templates') . "><div class='card'><div class='card-header'><h3 class='card-title'>Templates de Work Package</h3></div><div class='card-body'>";
echo "<p class='text-muted'>Configure o Markdown enviado como descrição para cada tipo permitido. O conteúdo bruto do chamado não é copiado automaticamente.</p>";
echo "<p class='form-hint'>Variáveis disponíveis: <code>{" . implode('}</code>, <code>{', WorkPackageTemplate::availableVariables()) . "}</code>. As variáveis <code>{descricao}</code>, <code>{{descricao}}</code>, <code>{conteudo}</code> e equivalentes são bloqueadas por segurança.</p>";
if ($typeNames === []) {
    echo "<div class='alert alert-info'>Salve e teste a conexão para carregar os tipos e configurar seus templates.</div>";
} else {
    echo "<div class='accordion mt-3' id='demandas-wp-templates'>";
    $templateIndex = 0;
    foreach ($typeNames as $typeName) {
        $normalizedType = mb_strtolower(trim($typeName));
        $template = WorkPackageTemplate::forType($typeName);
        if ($template === '' && $normalizedType === 'user story') {
            $template = WorkPackageTemplate::defaultUserStory();
        }
        $id = 'demandas-template-' . $templateIndex;
        echo "<div class='accordion-item'><h2 class='accordion-header'><button class='accordion-button" . ($templateIndex > 0 ? ' collapsed' : '') . "' type='button' data-bs-toggle='collapse' data-bs-target='#{$id}'>" . htmlspecialchars($typeName) . "</button></h2>";
        echo "<div id='{$id}' class='accordion-collapse collapse" . ($templateIndex === 0 ? ' show' : '') . "' data-bs-parent='#demandas-wp-templates'><div class='accordion-body'>";
        echo "<label class='form-label' for='{$id}-markdown'>Template Markdown</label><textarea class='form-control font-monospace' id='{$id}-markdown' name='work_package_templates[" . htmlspecialchars($typeName, ENT_QUOTES) . "]' rows='18' maxlength='50000' placeholder='Informe o template Markdown para este tipo de WP'>" . htmlspecialchars($template) . "</textarea>";
        echo "<div class='form-hint'>A criação deste tipo ficará bloqueada enquanto o template estiver vazio.</div></div></div></div>";
        $templateIndex++;
    }
    echo '</div>';
}

echo '<script>window.demandasClassificationData = ' . json_encode([
    'optionsByField' => $fieldsPluginOptions,
    'typeNames' => array_values($typeNames),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';

echo "<div class='mt-4 d-flex gap-2'><button class='btn btn-primary' name='save' value='1'>Salvar todas as configurações administrativas</button>";
echo "<button class='btn btn-outline-primary' name='test_connection' value='1'>Salvar e testar conexão</button></div></form></div></div></div>";

echo '<div' . demandasTabPaneAttributes('demandas-tutorial', $activeTab === 'tutorial') . '>';
demandasRenderConfigurationTutorial();
echo '</div>';
}

echo '</div></div>';

echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', () => {
    const phaseBody = document.querySelector('#demandas-phases-table tbody');
    const addPhase = document.getElementById('demandas-add-phase');
    let phaseIndex = phaseBody ? phaseBody.children.length : 0;
    addPhase?.addEventListener('click', () => {
        const row = document.createElement('tr');
        row.innerHTML = `<td><input type="hidden" name="public_phases[${phaseIndex}][id]" value=""><input class="form-control" name="public_phases[${phaseIndex}][name]" required></td><td><button class="btn btn-outline-danger demandas-remove-phase" type="button" aria-label="Remover fase"><i class="ti ti-trash"></i></button></td>`;
        phaseBody.appendChild(row);
        phaseIndex++;
    });
    phaseBody?.addEventListener('click', event => {
        const button = event.target.closest('.demandas-remove-phase');
        if (button) button.closest('tr').remove();
    });

    const classificationMode = document.getElementById('classification_mode');
    const fieldsPluginField = document.getElementById('classification_fields_plugin_field_id');
    const classificationBody = document.querySelector('#demandas-classification-rules tbody');
    const classificationWrapper = document.getElementById('demandas-classification-rules-wrapper');
    const addClassification = document.getElementById('demandas-add-classification');
    const classificationData = window.demandasClassificationData || {optionsByField: {}, typeNames: []};
    let classificationIndex = classificationBody ? classificationBody.children.length : 0;
    const escapeHtml = value => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const renderAssistedRules = () => {
        if (!classificationBody) return;
        classificationBody.innerHTML = '';
        classificationIndex = 0;
        const options = classificationData.optionsByField[String(fieldsPluginField?.value || '')] || {};
        Object.entries(options).forEach(([value, label]) => {
            const row = document.createElement('tr');
            const typeChoices = (classificationData.typeNames || []).map(typeName => {
                const escapedType = escapeHtml(typeName);
                return `<label class="form-check"><input class="form-check-input" type="checkbox" name="classification_rules[${classificationIndex}][allowed_type_names][]" value="${escapedType}"><span class="form-check-label">${escapedType}</span></label>`;
            }).join('');
            row.innerHTML = `<td><strong>${escapeHtml(label)}</strong><div class="text-muted small">Valor interno: ${escapeHtml(value)}</div><input type="hidden" name="classification_rules[${classificationIndex}][value]" value="${escapeHtml(value)}"><input type="hidden" name="classification_rules[${classificationIndex}][label]" value="${escapeHtml(label)}"></td><td><div class="d-flex flex-wrap gap-3">${typeChoices}</div></td><td class="demandas-manual-rule-column d-none"></td>`;
            classificationBody.appendChild(row);
            classificationIndex++;
        });
    };

    const addManualRule = () => {
        if (!classificationBody) return;
        const row = document.createElement('tr');
        row.innerHTML = `<td><div class="row g-2"><div class="col-md-5"><input class="form-control" name="classification_rules[${classificationIndex}][value]" placeholder="Valor armazenado" required></div><div class="col-md-7"><input class="form-control" name="classification_rules[${classificationIndex}][label]" placeholder="Nome apresentado" required></div></div></td><td><input class="form-control" name="classification_rules[${classificationIndex}][allowed_types]" placeholder="Bug, User Story"></td><td><button class="btn btn-outline-danger demandas-remove-classification" type="button"><i class="ti ti-trash"></i></button></td>`;
        classificationBody.appendChild(row);
        classificationIndex++;
    };

    const refreshClassificationSource = () => {
        document.querySelectorAll('.demandas-classification-source').forEach(element => element.classList.add('d-none'));
        document.querySelectorAll('.demandas-source-' + classificationMode?.value).forEach(element => element.classList.remove('d-none'));
        const mode = classificationMode?.value || 'none';
        const manual = mode === 'ticket_field' || mode === 'related_table';
        classificationWrapper?.classList.toggle('d-none', mode === 'none');
        addClassification?.classList.toggle('d-none', !manual);
        document.querySelectorAll('.demandas-manual-rule-column').forEach(element => element.classList.toggle('d-none', !manual));
    };
    classificationMode?.addEventListener('change', () => {
        const mode = classificationMode.value;
        if (classificationBody) {
            classificationBody.innerHTML = '';
            classificationIndex = 0;
        }
        if (mode === 'fields_plugin') renderAssistedRules();
        if (mode === 'ticket_field' || mode === 'related_table') addManualRule();
        refreshClassificationSource();
    });
    fieldsPluginField?.addEventListener('change', renderAssistedRules);
    refreshClassificationSource();
    addClassification?.addEventListener('click', addManualRule);
    classificationBody?.addEventListener('click', event => event.target.closest('.demandas-remove-classification')?.closest('tr')?.remove());

    const secret = document.getElementById('webhook_secret');
    const toggle = document.getElementById('demandas-toggle-secret');
    const copy = document.getElementById('demandas-copy-secret');
    const feedback = document.getElementById('demandas-secret-feedback');

    if (!secret || !toggle || !copy || !feedback) {
        return;
    }

    toggle.addEventListener('click', () => {
        const showing = secret.type === 'text';
        secret.type = showing ? 'password' : 'text';
        toggle.textContent = showing ? 'Mostrar' : 'Ocultar';
        toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
        feedback.textContent = showing
            ? 'O segredo permanece oculto até você clicar em Mostrar.'
            : 'O segredo está visível. Evite compartilhá-lo ou incluí-lo em capturas de tela.';
    });

    copy.addEventListener('click', async () => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(secret.value);
            } else {
                const originalType = secret.type;
                secret.type = 'text';
                secret.select();
                document.execCommand('copy');
                secret.type = originalType;
            }
            feedback.textContent = 'Segredo copiado para a área de transferência.';
        } catch (error) {
            feedback.textContent = 'Não foi possível copiar automaticamente. Clique em Mostrar e copie o valor selecionado.';
        }
    });
});
</script>
HTML;

Html::footer();
