<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class Config
{
    public const CONTEXT = 'plugin:demandas';
    private const USER_TOKENS_TABLE = 'glpi_plugin_demandas_user_tokens';

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
            'openproject_automation_api_token',
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
                $value = trim((string) $input[$key]);
                // O campo permanece vazio na tela para não reenviar o segredo
                // ao navegador. Um valor vazio mantém o token já salvo.
                if ($key === 'openproject_automation_api_token' && $value === '') {
                    continue;
                }
                $values[$key] = $value;
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

    public static function isAutomationReady(): bool
    {
        return self::get('openproject_internal_url', '') !== ''
            && self::automationToken() !== '';
    }

    /**
     * Mantido como alias de compatibilidade para os fluxos automáticos que
     * já utilizavam a configuração única do plugin.
     */
    public static function isReady(): bool
    {
        return self::isAutomationReady();
    }

    public static function automationToken(): string
    {
        $token = trim((string) self::get('openproject_automation_api_token', ''));
        // Instalações anteriores possuíam somente um token global. Ele é
        // tratado como automático até a migração persistir o novo campo.
        return $token !== '' ? $token : trim((string) self::get('openproject_api_token', ''));
    }

    public static function personalToken(?int $userId = null): string
    {
        $userId ??= (int) \Session::getLoginUserID();
        if ($userId <= 0) {
            return '';
        }

        $db = \DBConnection::getReadConnection();
        foreach ($db->request([
            'SELECT' => ['openproject_api_token'],
            'FROM' => self::USER_TOKENS_TABLE,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]) as $row) {
            return trim((string) ($row['openproject_api_token'] ?? ''));
        }

        return '';
    }

    public static function hasPersonalToken(?int $userId = null): bool
    {
        return self::get('openproject_internal_url', '') !== ''
            && self::personalToken($userId) !== '';
    }

    public static function savePersonalToken(int $userId, string $token): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Usuário inválido para salvar o token do OpenProject.');
        }

        $token = trim($token);
        $db = \DBConnection::getReadConnection();
        $existing = null;
        foreach ($db->request([
            'FROM' => self::USER_TOKENS_TABLE,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]) as $row) {
            $existing = $row;
            break;
        }

        if ($token === '') {
            if ($existing === null) {
                throw new \InvalidArgumentException('Informe um token de acesso do OpenProject.');
            }
            return;
        }

        $values = [
            'users_id' => $userId,
            'openproject_api_token' => $token,
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($existing !== null) {
            $db->update(self::USER_TOKENS_TABLE, $values, ['id' => (int) $existing['id']]);
            return;
        }

        $values['date_creation'] = date('Y-m-d H:i:s');
        $db->insert(self::USER_TOKENS_TABLE, $values);
    }

    public static function isActiveSuperAdmin(): bool
    {
        $profile = $_SESSION['glpiactiveprofile'] ?? [];
        $name = trim((string) ($profile['name'] ?? ''));
        if ($name === '' && (int) ($profile['id'] ?? 0) > 0) {
            $db = \DBConnection::getReadConnection();
            foreach ($db->request([
                'SELECT' => ['name'],
                'FROM' => 'glpi_profiles',
                'WHERE' => ['id' => (int) $profile['id']],
                'LIMIT' => 1,
            ]) as $row) {
                $name = trim((string) ($row['name'] ?? ''));
            }
        }

        return mb_strtolower($name) === 'super-admin';
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
