<?php

declare(strict_types=1);

require_once '/var/www/glpi/vendor/autoload.php';

$environments = \Glpi\Application\Environment::cases();
$environment = $environments[0];
foreach ($environments as $candidate) {
    if (stripos($candidate->name, 'prod') !== false || stripos((string) $candidate->value, 'prod') !== false) {
        $environment = $candidate;
        break;
    }
}

$kernel = new \Glpi\Kernel\Kernel($environment->value, false);
$kernel->boot();

global $DB;
$DB = DBConnection::getReadConnection();
if ($DB === null) {
    throw new RuntimeException('Não foi possível inicializar a conexão com o banco do GLPI.');
}

$checks = [
    'version' => GLPI_VERSION,
    'entities' => (int) $DB->request(['COUNT' => 'count', 'FROM' => 'glpi_entities'])->current()['count'],
    'groups' => (int) $DB->request(['COUNT' => 'count', 'FROM' => 'glpi_groups'])->current()['count'],
    'profiles' => (int) $DB->request(['COUNT' => 'count', 'FROM' => 'glpi_profiles'])->current()['count'],
    'users' => (int) $DB->request(['COUNT' => 'count', 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1]])->current()['count'],
    'tickets' => (int) $DB->request(['COUNT' => 'count', 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0]])->current()['count'],
];

$checks['ready'] = version_compare((string) $checks['version'], '11.0.9', '==')
    && $checks['entities'] >= 16
    && $checks['groups'] >= 9
    && $checks['tickets'] >= 10;

echo 'HML_RESULT=' . json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($checks['ready'] ? 0 : 1);
