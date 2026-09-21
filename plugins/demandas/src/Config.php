<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class Config
{
    public const CONTEXT = 'plugin:demandas';

    public static function all(): array
    {
        return \Config::getConfigurationValues(self::CONTEXT);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::all();
        return $values[$key] ?? $default;
    }

    public static function save(array $input): void
    {
        $allowed = [
            'openproject_internal_url',
            'openproject_external_url',
            'glpi_external_url',
            'openproject_api_token',
            'status_mapping_json',
            'public_phases_json',
            'status_rules_json',
            'webhook_secret',
            'request_timeout',
            'label_public_phase',
            'label_demand_evolution',
            'label_management_dashboard',
            'classification_source_json',
            'classification_rules_json',
            'ticket_log_enabled',
            'label_ticket_log',
            'work_package_templates_json',
        ];

        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = trim((string) $input[$key]);
            }
        }

        if (isset($values['openproject_internal_url'])) {
            $values['openproject_internal_url'] = rtrim($values['openproject_internal_url'], '/');
        }
        if (isset($values['openproject_external_url'])) {
            $values['openproject_external_url'] = rtrim($values['openproject_external_url'], '/');
        }
        if (isset($values['glpi_external_url'])) {
            $values['glpi_external_url'] = rtrim($values['glpi_external_url'], '/');
        }

        \Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function isReady(): bool
    {
        return self::get('openproject_internal_url', '') !== ''
            && self::get('openproject_api_token', '') !== '';
    }

    public static function label(string $name): string
    {
        $defaults = [
            'public_phase' => 'Fase Pública',
            'demand_evolution' => 'Evolução da Demanda',
            'management_dashboard' => 'Visão Gerencial de Demandas',
            'ticket_log' => 'Log do Chamado',
        ];
        $default = $defaults[$name] ?? $name;
        $value = trim((string) self::get('label_' . $name, $default));
        return $value !== '' ? mb_substr($value, 0, 100) : $default;
    }

    public static function webhookSecret(): string
    {
        $secret = trim((string) self::get('webhook_secret', ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            \Config::setConfigurationValues(self::CONTEXT, ['webhook_secret' => $secret]);
        }

        return $secret;
    }
}
