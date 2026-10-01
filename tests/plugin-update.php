<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'db' || getenv('GLPI_DB_NAME') !== 'glpi_e2e') {
    throw new RuntimeException('Use exclusivamente o banco E2E isolado.');
}
require '/var/www/glpi/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production', false);
$kernel->boot();
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Demandas\\';
    if (str_starts_with($class, $prefix)) require_once '/var/www/glpi/plugins/demandas/src/' . substr($class, strlen($prefix)) . '.php';
});
require_once '/var/www/glpi/plugins/demandas/setup.php';
use GlpiPlugin\Demandas\Config as DC;
use GlpiPlugin\Demandas\PluginUpdateService as Update;
function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$before = Config::getConfigurationValues(DC::CONTEXT);
$keys = ['plugin_update_check_enabled','plugin_update_latest_version','plugin_update_release_url','plugin_update_asset_url','plugin_update_last_checked_at','plugin_update_last_error'];
try {
    Config::setConfigurationValues(DC::CONTEXT, ['plugin_update_latest_version'=>'999.0.0']);
    check(Update::status()['available'] === true, 'versão superior sinalizada');
    Config::setConfigurationValues(DC::CONTEXT, ['plugin_update_latest_version'=>PLUGIN_DEMANDAS_VERSION]);
    check(Update::status()['available'] === false, 'versão igual não sinalizada');
    Config::setConfigurationValues(DC::CONTEXT, ['plugin_update_latest_version'=>'0.1.0','plugin_update_check_enabled'=>'0']);
    check(Update::status()['available'] === false, 'versão anterior não sinalizada');
    $disabled = Config::getConfigurationValues(DC::CONTEXT);
    check(Update::cronCheckRelease() && $disabled === Config::getConfigurationValues(DC::CONTEXT), 'verificação desabilitada não altera configuração');
    $url = new ReflectionMethod(Update::class, 'githubUrl');
    check($url->invoke(null, 'http://github.com/example') === '' && $url->invoke(null, 'https://github.com.evil.invalid/file') === '', 'HTTPS e domínio permitido obrigatórios');
    check($url->invoke(null, 'https://github.com/euzebioalves/glpi-gestao-demandas/releases') !== '', 'domínio oficial aceito');
    global $DB;
    $cron = $DB->request(['FROM'=>'glpi_crontasks','WHERE'=>['itemtype'=>Update::class,'name'=>'checkRelease']])->current();
    check((int)($cron['frequency'] ?? 0) === DAY_TIMESTAMP, 'tarefa diária registrada');
    if (in_array('--network', $argv, true)) {
        $status = Update::refresh();
        check($status['latest_version'] !== '' && str_starts_with($status['release_url'], 'https://github.com/euzebioalves/glpi-gestao-demandas/releases/'), 'consulta real da release oficial sem credenciais');
    }
} finally {
    $restore = [];
    foreach ($keys as $key) $restore[$key] = $before[$key] ?? '';
    Config::setConfigurationValues(DC::CONTEXT, $restore);
}
