<?php

declare(strict_types=1);

// Execute somente no contêiner descartável descrito em docs/RELEASE_0.18.3.md.
if (getenv('GLPI_DB_HOST') !== 'demandas-release-0183-db') {
    throw new RuntimeException('Teste permitido apenas no banco Docker isolado da release.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production', false);
$kernel->boot();
global $DB;
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) {
        require_once '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
    }
});
require_once '/var/www/glpi/plugins/demandas/setup.php';
require_once '/var/www/glpi/plugins/demandas/hook.php';
$DB->doQuery("SET time_zone = '-03:00'");
$DB->allow_datetime = false;
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function snapshot(): array
{
    global $DB;
    $data = [];
    foreach ($DB->request(['FROM' => 'information_schema.TABLES', 'WHERE' => [
        'TABLE_SCHEMA' => $DB->dbdefault,
        'TABLE_NAME' => ['LIKE', 'glpi_plugin_demandas_%'],
    ]]) as $table) {
        $name = $table['TABLE_NAME'];
        $data[$name] = array_values(iterator_to_array($DB->request(['FROM' => $name, 'ORDER' => 'id'])));
    }
    ksort($data);
    return $data;
}
function assertSchema(): void
{
    global $DB;
    foreach ($DB->request(['FROM' => 'information_schema.COLUMNS', 'WHERE' => [
        'TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => ['LIKE', 'glpi_plugin_demandas_%'],
        'DATA_TYPE' => 'datetime',
    ]]) as $column) {
        throw new RuntimeException('DATETIME restante: ' . $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']);
    }
    check($DB->getField('glpi_plugin_demandas_punches', 'punch_at', false)['Null'] === 'NO', 'Ponto deve continuar obrigatório.');
    check($DB->getField('glpi_plugin_demandas_time_entries', 'spent_on', false)['Type'] === 'date', 'Data de lançamento alterada.');
    check($DB->getField('glpi_plugin_demandas_time_entries', 'started_at', false)['Type'] === 'time', 'Horário de início alterado.');
}

$mode = $argv[1] ?? '';
if ($mode === 'profiles') {
    foreach (['Super-Admin', 'Technician'] as $profileName) {
        $profile = $DB->request(['FROM'=>'glpi_profiles', 'WHERE'=>['name'=>$profileName]])->current();
        foreach (GlpiPlugin\Demandas\Profile::definitions() as $right => $_label) {
            $DB->update('glpi_profilerights', ['rights'=>READ], ['profiles_id'=>(int)$profile['id'], 'name'=>$right]);
        }
    }
    echo "PASS: perfis fictícios administrativo e técnico habilitados para testes HTTP.\n";
    exit;
}
if ($mode === 'clean-fixtures') {
    plugin_demandas_uninstall();
    echo "PASS: somente as tabelas fictícias do contêiner descartável foram removidas.\n";
    exit;
}
if ($mode === 'seed') {
    $date = '2026-09-23 08:15:00';
    $fixtures = [
        'links' => ['tickets_id'=>1, 'openproject_work_package_id'=>123, 'public_phase'=>'Em análise', 'date_creation'=>$date, 'date_mod'=>$date],
        'events' => ['tickets_id'=>1, 'openproject_work_package_id'=>123, 'event_type'=>'test', 'date_creation'=>$date],
        'user_time_settings' => ['users_id'=>2, 'bank_initial_minutes'=>-30, 'date_creation'=>$date, 'date_mod'=>null],
        'punches' => ['users_id'=>2, 'punch_at'=>$date, 'created_by'=>2, 'date_creation'=>$date],
        'absences' => ['users_id'=>2, 'absence_date'=>'2026-09-22', 'kind'=>'justified', 'created_by'=>2, 'date_creation'=>$date],
        'absence_files' => ['absences_id'=>1, 'users_id'=>2, 'original_name'=>'ficticio.pdf', 'stored_name'=>'fixture.pdf', 'date_creation'=>$date],
        'holidays' => ['name'=>'Feriado fictício', 'holiday_date'=>'2026-09-07', 'created_by'=>2, 'date_creation'=>$date],
        'user_rights' => ['users_id'=>2, 'right_name'=>'demandas_view_time_portal', 'decision'=>1, 'date_mod'=>$date],
        'time_entries' => ['users_id'=>2, 'tickets_id'=>1, 'openproject_work_package_id'=>123, 'spent_on'=>'2026-09-23', 'started_at'=>'08:15:00', 'minutes'=>0, 'created_by'=>2, 'date_creation'=>$date, 'date_mod'=>null],
        'time_audit' => ['action'=>'fixture', 'actor_users_id'=>2, 'details_json'=>'{"test":true}', 'date_creation'=>$date],
        'user_tokens' => ['users_id'=>2, 'openproject_api_token'=>'fixture-not-a-credential', 'date_creation'=>$date, 'date_mod'=>null],
    ];
    foreach ($fixtures as $suffix => $row) {
        $DB->insert('glpi_plugin_demandas_' . $suffix, $row);
    }
    file_put_contents('/tmp/demandas-before.json', json_encode(snapshot(), JSON_THROW_ON_ERROR));
    echo "PASS: dados fictícios da versão anterior gravados em 11 tabelas.\n";
    exit;
}

set_error_handler(static function (int $severity, string $message): bool {
    if (str_contains($message, 'DATETIME') || str_contains($message, 'direct queries')) {
        throw new ErrorException($message, 0, $severity);
    }
    return false;
});
if ($mode === 'upgrade') {
    $before = json_decode(file_get_contents('/tmp/demandas-before.json'), true, 512, JSON_THROW_ON_ERROR);
    check(plugin_demandas_install(), 'Atualização falhou.');
    assertSchema();
    check(snapshot() === $before, 'Atualização alterou dados existentes.');
    check(plugin_demandas_install(), 'Repetição falhou.');
    check(snapshot() === $before, 'Repetição alterou dados existentes.');
    $DB->update('glpi_plugin_demandas_punches', ['note'=>'Edição sem alterar horário'], ['users_id'=>2]);
    $punch = $DB->request(['FROM'=>'glpi_plugin_demandas_punches', 'WHERE'=>['users_id'=>2]])->current();
    check($punch['punch_at'] === '2026-09-23 08:15:00', 'Horário mudou automaticamente.');
    echo "PASS: atualização, repetição, 11 tabelas preservadas, NULL, DATE, TIME e horário -03:00.\n";

    // Pré-validação global: nenhuma coluna deve migrar se outra contém data inválida.
    $DB->allow_datetime = true;
    $DB->doQuery('ALTER TABLE glpi_plugin_demandas_user_time_settings MODIFY date_creation DATETIME DEFAULT NULL');
    $DB->doQuery('ALTER TABLE glpi_plugin_demandas_punches MODIFY punch_at DATETIME NOT NULL');
    $DB->allow_datetime = false;
    foreach (['1960-01-01 08:00:00', '9999-12-31 08:00:00'] as $invalid) {
        $DB->update('glpi_plugin_demandas_punches', ['punch_at'=>$invalid], ['users_id'=>2]);
        $rejected = false;
        try {
            plugin_demandas_migrate_timestamps($DB);
        } catch (RuntimeException $e) {
            $rejected = str_contains($e->getMessage(), 'fora do intervalo');
        }
        check($rejected, 'Data fora do intervalo não foi rejeitada.');
        check($DB->getField('glpi_plugin_demandas_user_time_settings', 'date_creation', false)['Type'] === 'datetime', 'Pré-validação executou DDL parcial.');
        check($DB->request(['FROM'=>'glpi_plugin_demandas_punches', 'WHERE'=>['users_id'=>2]])->current()['punch_at'] === $invalid, 'Data inválida foi descartada.');
    }
    $DB->update('glpi_plugin_demandas_punches', ['punch_at'=>'2026-09-23 08:15:00'], ['users_id'=>2]);
    plugin_demandas_migrate_timestamps($DB);
    assertSchema();
    echo "PASS: datas inválidas preservadas e migração retomada após revisão.\n";
} elseif ($mode === 'fresh') {
    check(!$DB->tableExists('glpi_plugin_demandas_links', false), 'Execute clean-fixtures em outro processo antes de fresh.');
    check(plugin_demandas_install(), 'Instalação limpa falhou.');
    assertSchema();
    $before = snapshot();
    check(plugin_demandas_install(), 'Segunda instalação falhou.');
    check(snapshot() === $before, 'Segunda instalação alterou os dados.');
    echo "PASS: instalação limpa e repetição sem avisos de DATETIME/consulta direta.\n";
    $DB->insert('glpi_plugin_demandas_absences', ['users_id'=>2, 'absence_date'=>date('Y-m-d'), 'kind'=>'justified', 'is_full_day'=>1, 'reason'=>'Teste de auditoria', 'created_by'=>2]);
    $ledger = (new GlpiPlugin\Demandas\TimeManagementService())->bankLedger(2);
    $neutral = array_values(array_filter($ledger['entries'], static fn(array $row): bool => $row['kind'] === 'justified_absence'));
    check(count($neutral) === 1 && $neutral[0]['minutes'] === 0 && str_contains($neutral[0]['details'], 'Teste de auditoria'), 'Auditoria de falta justificada falhou.');
    check($ledger['current_minutes'] === 0, 'Falta justificada alterou o saldo.');
    echo "PASS: auditoria de falta justificada com motivo e saldo neutro.\n";
    $body = '{"action":"test:ignored"}';
    $handler = new GlpiPlugin\Demandas\WebhookHandler();
    $result = $handler->handle($body, 'sha256=' . hash_hmac('sha256', $body, GlpiPlugin\Demandas\Config::webhookSecret()));
    check($result['processed'] === false, 'Webhook assinado não foi validado.');
    $rejected = false;
    try {
        $handler->handle($body, 'invalid');
    } catch (GlpiPlugin\Demandas\WebhookAuthenticationException) {
        $rejected = true;
    }
    check($rejected, 'Assinatura inválida aceita.');
    echo "PASS: webhook assinado aceito e assinatura inválida rejeitada.\n";
} else {
    throw new RuntimeException('Modo esperado: seed, upgrade, clean-fixtures ou fresh.');
}
