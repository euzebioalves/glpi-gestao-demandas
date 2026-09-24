<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use Glpi\Http\Firewall;
use GlpiPlugin\Demandas\TicketDemand;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\ManagementDashboard;
use GlpiPlugin\Demandas\TicketLog;
use GlpiPlugin\Demandas\WorkforceHub;
use GlpiPlugin\Demandas\TimeEntryHub;
use GlpiPlugin\Demandas\UserTimeProfile;
use GlpiPlugin\Demandas\OpenProjectPersonalToken;
use GlpiPlugin\Demandas\WorkPackageMonitoringHub;

define('PLUGIN_DEMANDAS_VERSION', '0.19.1');
define('PLUGIN_DEMANDAS_MIN_GLPI', '11.0.0');
define('PLUGIN_DEMANDAS_MAX_GLPI', '11.0.99');

function plugin_demandas_boot(): void
{
    \Glpi\Http\SessionManager::registerPluginStatelessPath('demandas', '#^/webhook\.php$#');
}

function plugin_init_demandas(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['demandas'] = true;
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['demandas'] = 'front/config.form.php';

    Firewall::addPluginStrategyForLegacyScripts(
        'demandas',
        '#^/webhook\.php$#',
        Firewall::STRATEGY_NO_CHECK
    );

    Plugin::registerClass(TicketDemand::class, [
        'addtabon' => Ticket::class,
    ]);
    Plugin::registerClass(TicketLog::class, [
        'addtabon' => Ticket::class,
    ]);
    Plugin::registerClass(DemandasProfile::class, [
        'addtabon' => Profile::class,
    ]);
    Plugin::registerClass(UserTimeProfile::class, [
        'addtabon' => User::class,
    ]);
    Plugin::registerClass(OpenProjectPersonalToken::class, [
        'addtabon' => Preference::class,
    ]);

    // GLPI resolves plugin assets against the plugin's /public directory.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['demandas'] = 'js/demandas.js';
    $PLUGIN_HOOKS[Hooks::POST_ITIL_INFO_SECTION]['demandas'] = 'plugin_demandas_post_itil_info_section';
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['demandas'] = [
        'management' => [ManagementDashboard::class, WorkforceHub::class, TimeEntryHub::class, WorkPackageMonitoringHub::class],
    ];
}

function plugin_version_demandas(): array
{
    return [
        'name' => 'Gestão de Demandas',
        'version' => PLUGIN_DEMANDAS_VERSION,
        'author' => 'Ponto iD',
        'license' => 'GPLv3+',
        'homepage' => 'https://www.pontoid.com.br',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_DEMANDAS_MIN_GLPI,
                'max' => PLUGIN_DEMANDAS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_demandas_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_DEMANDAS_MIN_GLPI, '<')) {
        echo 'O plugin Gestão de Demandas requer GLPI 11.';
        return false;
    }

    return true;
}

function plugin_demandas_check_config(bool $verbose = false): bool
{
    return true;
}
