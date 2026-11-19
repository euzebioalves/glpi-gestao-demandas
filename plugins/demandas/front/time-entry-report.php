<?php
declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\Config;
use GlpiPlugin\Demandas\IntegrationSecurity;
use GlpiPlugin\Demandas\OpenProjectClient;
use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkLoginUser();
AccessPolicy::check(DemandasProfile::VIEW_TIME_PORTAL);
AccessPolicy::check(DemandasProfile::LOG_OWN_TIME);
header('Cache-Control: no-store');

// Resolve only the authenticated user's entry, never a caller-supplied URL or WP.
$entry = DBConnection::getReadConnection()->request([
    'FROM' => 'glpi_plugin_demandas_time_entries',
    'WHERE' => ['id' => (int) ($_GET['id'] ?? 0), 'users_id' => (int) Session::getLoginUserID()],
    'LIMIT' => 1,
])->current();
if (!$entry || (int) ($entry['openproject_time_entry_id'] ?? 0) <= 0) {
    throw new Glpi\Exception\Http\AccessDeniedHttpException();
}
// No session writes below: a slow remote report must not block another GLPI tab.
session_write_close();
try {
    $wp = (int) $entry['openproject_work_package_id'];
    $client = OpenProjectClient::forCurrentUser();
    $workPackage = $client->getWorkPackage($wp);
    if ((int) ($workPackage['id'] ?? 0) !== $wp
        || !preg_match('~/projects/([1-9][0-9]*)$~', (string) ($workPackage['_links']['project']['href'] ?? ''), $matches)) {
        throw new RuntimeException('Não foi possível identificar o projeto desta Work Package.');
    }
    $project = $client->getProject((int) $matches[1]);
    $identifier = trim((string) ($project['identifier'] ?? ''));
    if ($identifier === '') throw new RuntimeException('O projeto não informou seu identificador.');
    $url = IntegrationSecurity::baseUrl((string) Config::get('openproject_external_url'))
        . '/projects/' . rawurlencode($identifier)
        . '/cost_reports?fields[]=WorkPackageId&operators[WorkPackageId]=%3D_child_work_packages&values[WorkPackageId]=' . $wp . '&set_filter=1';
    header('Location: ' . $url, true, 302);
} catch (Throwable $error) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Relatório de tempo</title><body><h1>Relatório indisponível</h1><p>'
        . IntegrationSecurity::html($error)
        . '</p><p>As entradas continuam salvas no GLPI. Recarregue esta página para tentar novamente.</p></body></html>';
}
