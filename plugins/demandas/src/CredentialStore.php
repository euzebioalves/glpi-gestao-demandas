<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

/** Native GLPI ciphertext, including support for security:change_key. */
final class CredentialStore
{
    public const KEYS = ['openproject_api_token', 'openproject_automation_api_token', 'webhook_secret'];
    private const MARKER = 'credentials_encrypted_0340';

    public static function encrypted(): bool
    {
        return (\Config::getConfigurationValues(Config::CONTEXT)[self::MARKER] ?? '') === '1';
    }

    public static function register(): void
    {
        global $PLUGIN_HOOKS;
        if (!self::encrypted()) {
            unset($PLUGIN_HOOKS[\Glpi\Plugin\Hooks::SECURED_CONFIGS]['demandas'], $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::SECURED_FIELDS]['demandas']);
            return;
        }
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::SECURED_CONFIGS]['demandas'] = self::KEYS;
        $PLUGIN_HOOKS[\Glpi\Plugin\Hooks::SECURED_FIELDS]['demandas'] = ['glpi_plugin_demandas_user_tokens.openproject_api_token'];
    }

    private static function key(): \GLPIKey
    {
        $key = new \GLPIKey();
        if ($key->hasReadErrors()) throw new \RuntimeException('A chave de criptografia do GLPI está indisponível. Solicite a verificação à infraestrutura.');
        return $key;
    }

    public static function encode(string $value): string
    {
        if ($value === '') return '';
        $key = self::key();
        $cipher = $key->encrypt($value);
        if (strlen($cipher) > 65535) throw new \RuntimeException('Uma credencial excede o tamanho suportado para proteção. A atualização foi interrompida sem descartar o valor anterior.');
        if ($cipher === '' || $key->decrypt($cipher) !== $value) throw new \RuntimeException('Não foi possível proteger a credencial. O valor anterior foi preservado.');
        return $cipher;
    }

    public static function decode(string $value): string
    {
        if ($value === '' || !self::encrypted()) return $value;
        $plain = self::key()->decrypt($value);
        if (!is_string($plain) || $plain === '') throw new \RuntimeException('Não foi possível ler a credencial protegida. Verifique a chave de criptografia do GLPI.');
        return $plain;
    }

    public static function migrate(): void
    {
        OperationGuard::run('credential-migration', static function (): void {
            if (!self::encrypted()) {
                self::key();
                OperationGuard::transaction(static function (): void {
                    global $DB;
                    foreach ($DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => Config::CONTEXT, 'name' => self::KEYS]]) as $row) {
                        OperationGuard::write($DB->update('glpi_configs', ['value' => self::encode((string) $row['value'])], ['id' => $row['id']]));
                    }
                    foreach ($DB->request(['FROM' => 'glpi_plugin_demandas_user_tokens']) as $row) {
                        OperationGuard::write($DB->update('glpi_plugin_demandas_user_tokens', ['openproject_api_token' => self::encode((string) $row['openproject_api_token'])], ['id' => $row['id']]));
                    }
                    OperationGuard::write($DB->insert('glpi_configs', ['context' => Config::CONTEXT, 'name' => self::MARKER, 'value' => '1']));
                });
            }
            self::register();
        });
    }

    public static function saveConfig(array $values): void
    {
        self::migrate();
        self::key();
        // Direct checked writes avoid silent failures in Config::setConfigurationValues.
        OperationGuard::transaction(static function () use ($values): void {
            global $DB;
            foreach ($values as $name => $value) {
                if (in_array($name, self::KEYS, true)) $value = self::encode((string) $value);
                $where = ['context' => Config::CONTEXT, 'name' => $name];
                $exists = count($DB->request(['FROM' => 'glpi_configs', 'WHERE' => $where])) > 0;
                OperationGuard::write($exists ? $DB->update('glpi_configs', ['value' => $value], $where) : $DB->insert('glpi_configs', $where + ['value' => $value]));
            }
        });
    }
}
