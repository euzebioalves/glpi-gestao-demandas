<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;

function plugin_demandas_install(): bool
{
    $db = DBConnection::getReadConnection();

    $db->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_links` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL,
            `openproject_work_package_id` int unsigned NOT NULL,
            `openproject_project_id` int unsigned DEFAULT NULL,
            `openproject_project_name` varchar(255) DEFAULT NULL,
            `openproject_project_identifier` varchar(255) DEFAULT NULL,
            `openproject_type_id` int unsigned DEFAULT NULL,
            `openproject_type_name` varchar(255) DEFAULT NULL,
            `openproject_status` varchar(120) DEFAULT NULL,
            `public_phase` varchar(120) NOT NULL DEFAULT 'Em análise',
            `public_message` text DEFAULT NULL,
            `last_synced_at` timestamp NULL DEFAULT NULL,
            `work_package_details_json` longtext DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ticket` (`tickets_id`),
            UNIQUE KEY `unicity_work_package` (`openproject_work_package_id`),
            KEY `idx_last_synced_at` (`last_synced_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD COLUMN IF NOT EXISTS `openproject_project_id` int unsigned DEFAULT NULL AFTER `openproject_work_package_id`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD COLUMN IF NOT EXISTS `openproject_project_name` varchar(255) DEFAULT NULL AFTER `openproject_project_id`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD COLUMN IF NOT EXISTS `openproject_project_identifier` varchar(255) DEFAULT NULL AFTER `openproject_project_name`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD COLUMN IF NOT EXISTS `openproject_type_name` varchar(255) DEFAULT NULL AFTER `openproject_type_id`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD COLUMN IF NOT EXISTS `work_package_details_json` longtext DEFAULT NULL AFTER `last_synced_at`');
    // Migração 0.12.0: um chamado pode possuir várias Work Packages. A WP
    // continua globalmente única, mas tickets_id deixa de ser chave única.
    $ticketUniqueIndex = $db->request([
        'FROM' => 'information_schema.STATISTICS',
        'WHERE' => [
            'TABLE_SCHEMA' => $db->dbdefault,
            'TABLE_NAME' => 'glpi_plugin_demandas_links',
            'INDEX_NAME' => 'unicity_ticket',
        ],
        'LIMIT' => 1,
    ]);
    foreach ($ticketUniqueIndex as $_index) {
        $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` DROP INDEX `unicity_ticket`');
        break;
    }
    $ticketIndex = $db->request([
        'FROM' => 'information_schema.STATISTICS',
        'WHERE' => [
            'TABLE_SCHEMA' => $db->dbdefault,
            'TABLE_NAME' => 'glpi_plugin_demandas_links',
            'INDEX_NAME' => 'idx_ticket',
        ],
        'LIMIT' => 1,
    ]);
    $hasTicketIndex = false;
    foreach ($ticketIndex as $_index) {
        $hasTicketIndex = true;
        break;
    }
    if (!$hasTicketIndex) {
        $db->doQuery('ALTER TABLE `glpi_plugin_demandas_links` ADD INDEX `idx_ticket` (`tickets_id`)');
    }

    $db->doQuery(
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_events` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL,
            `openproject_work_package_id` int unsigned NOT NULL,
            `event_type` varchar(80) NOT NULL,
            `source` varchar(40) NOT NULL DEFAULT 'manual',
            `previous_status` varchar(120) DEFAULT NULL,
            `new_status` varchar(120) DEFAULT NULL,
            `previous_public_phase` varchar(120) DEFAULT NULL,
            `new_public_phase` varchar(120) DEFAULT NULL,
            `is_success` tinyint NOT NULL DEFAULT 1,
            `message` text DEFAULT NULL,
            `details_json` longtext DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_ticket_date` (`tickets_id`, `date_creation`),
            KEY `idx_wp_date` (`openproject_work_package_id`, `date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_events` ADD COLUMN IF NOT EXISTS `details_json` longtext DEFAULT NULL AFTER `message`');

    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_user_time_settings` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `users_id` int unsigned NOT NULL,
        `work_start` time NOT NULL DEFAULT '08:00:00', `work_end` time NOT NULL DEFAULT '17:00:00',
        `lunch_minutes` smallint unsigned NOT NULL DEFAULT 60, `tolerance_minutes` smallint unsigned NOT NULL DEFAULT 5,
        `working_days_json` varchar(100) NOT NULL DEFAULT '[1,2,3,4,5]', `state_code` varchar(2) DEFAULT NULL,
        `municipality` varchar(120) DEFAULT NULL, `bank_initial_minutes` int NOT NULL DEFAULT 0,
        `bank_start_date` date DEFAULT NULL, `openproject_user_href` varchar(255) DEFAULT NULL,
        `openproject_user_name` varchar(255) DEFAULT NULL, `date_creation` datetime DEFAULT NULL, `date_mod` datetime DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uniq_user` (`users_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_punches` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `users_id` int unsigned NOT NULL, `punch_at` datetime NOT NULL,
        `punch_type` varchar(30) DEFAULT NULL, `nsr` varchar(50) DEFAULT NULL,
        `source` varchar(30) NOT NULL DEFAULT 'manual', `note` varchar(500) DEFAULT NULL, `created_by` int unsigned NOT NULL,
        `date_creation` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_punch` (`users_id`,`punch_at`),
        KEY `idx_user_date` (`users_id`,`punch_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_punches` ADD COLUMN IF NOT EXISTS `punch_type` varchar(30) DEFAULT NULL AFTER `punch_at`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_punches` ADD COLUMN IF NOT EXISTS `nsr` varchar(50) DEFAULT NULL AFTER `punch_type`');
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_absences` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `users_id` int unsigned NOT NULL, `absence_date` date NOT NULL,
        `kind` varchar(20) NOT NULL, `minutes` smallint unsigned DEFAULT NULL, `reason` text DEFAULT NULL,
        `created_by` int unsigned NOT NULL, `date_creation` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `idx_user_date` (`users_id`,`absence_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_absences` ADD COLUMN IF NOT EXISTS `starts_at` time DEFAULT NULL AFTER `minutes`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_absences` ADD COLUMN IF NOT EXISTS `ends_at` time DEFAULT NULL AFTER `starts_at`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_absences` ADD COLUMN IF NOT EXISTS `is_full_day` tinyint NOT NULL DEFAULT 1 AFTER `ends_at`');
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_absence_files` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `absences_id` int unsigned NOT NULL, `users_id` int unsigned NOT NULL,
        `original_name` varchar(255) NOT NULL, `stored_name` varchar(120) NOT NULL, `mime_type` varchar(120) DEFAULT NULL,
        `file_size` int unsigned NOT NULL DEFAULT 0, `date_creation` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `idx_absence` (`absences_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_holidays` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `holiday_date` date NOT NULL,
        `scope` varchar(20) NOT NULL DEFAULT 'national', `state_code` varchar(2) DEFAULT NULL, `municipality` varchar(120) DEFAULT NULL,
        `is_working_day` tinyint NOT NULL DEFAULT 0, `substitute_date` date DEFAULT NULL, `notes` text DEFAULT NULL,
        `created_by` int unsigned NOT NULL, `date_creation` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `idx_date` (`holiday_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_user_rights` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `users_id` int unsigned NOT NULL, `right_name` varchar(120) NOT NULL,
        `decision` tinyint NOT NULL, `date_mod` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uniq_user_right` (`users_id`,`right_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_time_entries` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `users_id` int unsigned NOT NULL, `tickets_id` int unsigned DEFAULT NULL,
        `openproject_work_package_id` int unsigned NOT NULL, `openproject_time_entry_id` int unsigned DEFAULT NULL,
        `spent_on` date NOT NULL, `started_at` time DEFAULT NULL, `ended_at` time DEFAULT NULL,
        `minutes` int unsigned NOT NULL DEFAULT 0, `activity_href` varchar(255) DEFAULT NULL, `activity_name` varchar(255) DEFAULT NULL,
        `comment` text DEFAULT NULL, `sync_status` varchar(20) NOT NULL DEFAULT 'pending', `is_success` tinyint NOT NULL DEFAULT 0, `error_message` text DEFAULT NULL,
        `created_by` int unsigned NOT NULL, `date_creation` datetime DEFAULT NULL, `date_mod` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `idx_user_date` (`users_id`,`spent_on`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_time_entries` ADD COLUMN IF NOT EXISTS `started_at` time DEFAULT NULL AFTER `spent_on`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_time_entries` ADD COLUMN IF NOT EXISTS `ended_at` time DEFAULT NULL AFTER `started_at`');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_time_entries` MODIFY COLUMN `minutes` int unsigned NOT NULL DEFAULT 0');
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_time_entries` ADD COLUMN IF NOT EXISTS `activity_href` varchar(255) DEFAULT NULL AFTER `minutes`');
    $db->doQuery("ALTER TABLE `glpi_plugin_demandas_time_entries` ADD COLUMN IF NOT EXISTS `sync_status` varchar(20) NOT NULL DEFAULT 'pending' AFTER `comment`");
    $db->doQuery('ALTER TABLE `glpi_plugin_demandas_time_entries` ADD COLUMN IF NOT EXISTS `date_mod` datetime DEFAULT NULL AFTER `date_creation`');
    $db->doQuery("UPDATE `glpi_plugin_demandas_time_entries` SET `sync_status`='synced' WHERE `openproject_time_entry_id` IS NOT NULL AND `openproject_time_entry_id` > 0");
    $db->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_demandas_time_audit` (
        `id` int unsigned NOT NULL AUTO_INCREMENT, `action` varchar(80) NOT NULL, `actor_users_id` int unsigned NOT NULL,
        `target_users_id` int unsigned DEFAULT NULL, `details_json` longtext DEFAULT NULL, `date_creation` datetime DEFAULT NULL,
        PRIMARY KEY (`id`), KEY `idx_actor_date` (`actor_users_id`,`date_creation`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        'openproject_internal_url' => 'http://openproject/api/v3',
        'openproject_external_url' => 'http://localhost:8280',
        'glpi_external_url' => 'http://localhost:8180',
        'openproject_api_token' => '',
        'status_mapping_json' => '{}',
        'public_phases_json' => json_encode([
            ['id' => 'analysis', 'name' => 'Em análise'],
            ['id' => 'specification', 'name' => 'Em especificação'],
            ['id' => 'prioritization', 'name' => 'Aguardando priorização'],
            ['id' => 'planned', 'name' => 'Planejada'],
            ['id' => 'development', 'name' => 'Em desenvolvimento'],
            ['id' => 'validation', 'name' => 'Em validação interna'],
            ['id' => 'homologation', 'name' => 'Aguardando homologação'],
            ['id' => 'completed', 'name' => 'Implementação concluída'],
            ['id' => 'cancelled', 'name' => 'Não seguirá para implementação'],
        ], JSON_UNESCAPED_UNICODE),
        'status_rules_json' => '{}',
        'webhook_secret' => bin2hex(random_bytes(32)),
        'request_timeout' => '15',
        'label_public_phase' => 'Fase Pública',
        'label_demand_evolution' => 'Evolução da Demanda',
        'label_management_dashboard' => 'Visão Gerencial de Demandas',
        'classification_source_json' => '{"mode":"none"}',
        'classification_rules_json' => '[]',
        'ticket_log_enabled' => '0',
        'label_ticket_log' => 'Log do Chamado',
        'holiday_overtime_multiplier' => '2',
    ];
    $current = Config::getConfigurationValues('plugin:demandas');
    Config::setConfigurationValues('plugin:demandas', $current + $defaults);

    $profileRight = new ProfileRight();
    foreach (DemandasProfile::getAllRights() as $right) {
        // addProfileRights() inserts the right for every GLPI profile and is
        // not idempotent. Only call it when this right has never been
        // registered; otherwise an update fails on the unicity constraint.
        if ($profileRight->find(['name' => $right['field']], [], 1) === []) {
            ProfileRight::addProfileRights([$right['field']]);
        }
    }

    if (($current['rights_initialized_060'] ?? '0') !== '1') {
        $profile = new Profile();
        foreach ($profile->find(['name' => 'Cliente — Gestão de Demandas']) as $profileId => $row) {
            foreach (DemandasProfile::definitions() as $right => $label) {
                DemandasProfile::setRight(
                    (int) $profileId,
                    $right,
                    $right === DemandasProfile::VIEW_PUBLIC
                );
            }
        }
        foreach ($profile->find(['name' => 'Requisitos — Gestão de Demandas']) as $profileId => $row) {
            $enabled = [
                DemandasProfile::VIEW_PUBLIC,
                DemandasProfile::VIEW_TECHNICAL,
                DemandasProfile::VIEW_HISTORY,
                DemandasProfile::CREATE_WORK_PACKAGE,
                DemandasProfile::SYNC_WORK_PACKAGE,
            ];
            foreach (DemandasProfile::definitions() as $right => $label) {
                DemandasProfile::setRight((int) $profileId, $right, in_array($right, $enabled, true));
            }
        }
        Config::setConfigurationValues('plugin:demandas', ['rights_initialized_060' => '1']);
    }

    // Repair profiles configured before 0.6.6. GLPI independently checks its
    // native followup right when building the ticket timeline.
    foreach ($profileRight->find(['name' => DemandasProfile::VIEW_PUBLIC]) as $row) {
        if (((int) ($row['rights'] ?? 0) & READ) === READ) {
            DemandasProfile::ensurePublicFollowupRight((int) $row['profiles_id']);
        }
    }

    if (($current['rights_initialized_070'] ?? '0') !== '1') {
        $profilesToEnable = [];
        foreach ($profileRight->find(['name' => DemandasProfile::MANAGE_CONFIG]) as $row) {
            if (((int) ($row['rights'] ?? 0) & READ) === READ) {
                $profilesToEnable[(int) $row['profiles_id']] = true;
            }
        }
        $profile = new Profile();
        foreach ($profile->find(['name' => 'Requisitos — Gestão de Demandas']) as $profileId => $row) {
            $profilesToEnable[(int) $profileId] = true;
        }
        foreach ($profile->find(['name' => 'Super-Admin']) as $profileId => $row) {
            $profilesToEnable[(int) $profileId] = true;
        }
        foreach (array_keys($profilesToEnable) as $profileId) {
            DemandasProfile::setRight($profileId, DemandasProfile::VIEW_DASHBOARD, true);
            DemandasProfile::setRight($profileId, DemandasProfile::EXPORT_DASHBOARD, true);
        }
        Config::setConfigurationValues('plugin:demandas', ['rights_initialized_070' => '1']);
    }

    if (($current['rights_initialized_090'] ?? '0') !== '1') {
        $profilesToEnable = [];
        foreach ($profileRight->find(['name' => DemandasProfile::MANAGE_CONFIG]) as $row) {
            if (((int) ($row['rights'] ?? 0) & READ) === READ) {
                $profilesToEnable[(int) $row['profiles_id']] = true;
            }
        }
        $profile = new Profile();
        foreach (['Requisitos — Gestão de Demandas', 'Super-Admin'] as $profileName) {
            foreach ($profile->find(['name' => $profileName]) as $profileId => $row) {
                $profilesToEnable[(int) $profileId] = true;
            }
        }
        foreach (array_keys($profilesToEnable) as $profileId) {
            DemandasProfile::setRight($profileId, DemandasProfile::VIEW_TICKET_LOG, true);
        }
        Config::setConfigurationValues('plugin:demandas', ['rights_initialized_090' => '1']);
    }

    if (($current['rights_initialized_160'] ?? '0') !== '1') {
        $profile = new Profile();
        foreach ($profile->find([]) as $profileId => $row) {
            $basic = ['Cliente — Gestão de Demandas', 'Requisitos — Gestão de Demandas', 'Super-Admin'];
            if (in_array((string)($row['name'] ?? ''), $basic, true)) {
                foreach ([DemandasProfile::VIEW_TIME_PORTAL, DemandasProfile::LOG_OWN_TIME, DemandasProfile::VIEW_OWN_ATTENDANCE] as $right) {
                    DemandasProfile::setRight((int)$profileId, $right, true);
                }
            }
            if ((string)($row['name'] ?? '') === 'Super-Admin') {
                foreach ([DemandasProfile::LOG_OTHERS_TIME, DemandasProfile::VIEW_TEAM_ATTENDANCE, DemandasProfile::MANAGE_ATTENDANCE, DemandasProfile::MANAGE_HOLIDAYS, DemandasProfile::MANAGE_TIME_ACCESS] as $right) {
                    DemandasProfile::setRight((int)$profileId, $right, true);
                }
            }
        }
        Config::setConfigurationValues('plugin:demandas', ['rights_initialized_160' => '1']);
    }

    return true;
}

function plugin_demandas_uninstall(): bool
{
    $db = DBConnection::getReadConnection();
    foreach (['time_audit','time_entries','user_rights','holidays','absence_files','absences','punches','user_time_settings'] as $table) {
        $db->doQuery('DROP TABLE IF EXISTS `glpi_plugin_demandas_' . $table . '`');
    }
    $db->doQuery('DROP TABLE IF EXISTS `glpi_plugin_demandas_events`');
    $db->doQuery('DROP TABLE IF EXISTS `glpi_plugin_demandas_links`');
    Config::deleteConfigurationValues('plugin:demandas');
    foreach (DemandasProfile::getAllRights() as $right) {
        ProfileRight::deleteProfileRights([$right['field']]);
    }

    return true;
}

function plugin_demandas_post_itil_info_section(mixed $params): void
{
    if (!DemandasProfile::has(DemandasProfile::VIEW_PUBLIC)) {
        return;
    }
    // GLPI 11 may call this hook with the ITIL object itself or with the
    // legacy parameter array, depending on the form renderer in use.
    $item = $params instanceof Ticket
        ? $params
        : (is_array($params) ? ($params['item'] ?? null) : null);
    if (!$item instanceof Ticket || $item->getID() <= 0) {
        return;
    }

    $links = \GlpiPlugin\Demandas\TicketDemand::findAllByTicket($item->getID());
    $link = $links[0] ?? null;
    if ($link === null) {
        return;
    }

    $phase = htmlspecialchars((string) ($link['public_phase'] ?? ''), ENT_QUOTES);
    $label = htmlspecialchars(\GlpiPlugin\Demandas\Config::label('public_phase'), ENT_QUOTES);
    $wpId = (int) ($link['openproject_work_package_id'] ?? 0);
    $wpUrl = $wpId > 0
        ? rtrim((string) \GlpiPlugin\Demandas\Config::get('openproject_external_url', ''), '/') . '/work_packages/' . $wpId
        : '';
    echo "<section id='demandas-public-phase-source' class='d-none' data-public-phase='{$phase}' data-public-phase-label='{$label}' data-work-package-id='{$wpId}' data-work-package-count='" . count($links) . "' data-work-package-url='" . htmlspecialchars($wpUrl, ENT_QUOTES) . "'></section>";
    echo "<script>window.dispatchEvent(new CustomEvent('demandas:phase-ready'));</script>";
}
