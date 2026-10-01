<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CronTask;
use RuntimeException;

/**
 * Checks only the fixed public GitHub release endpoint. It never downloads or
 * installs code: deployment is deliberately delegated to an external agent.
 */
final class PluginUpdateService extends \CommonDBTM
{
    private const LATEST_RELEASE_URL = 'https://api.github.com/repos/euzebioalves/glpi-gestao-demandas/releases/latest';

    public static function cronInfo($name): array
    {
        return $name === 'checkRelease'
            ? ['description' => 'Verificar atualizações publicadas do plugin Gestão de Demandas']
            : [];
    }

    public static function cronCheckRelease($task = null): bool
    {
        if ((string) Config::get('plugin_update_check_enabled', '1') !== '1') {
            return true;
        }

        try {
            self::refresh();
            if ($task instanceof CronTask) {
                $task->addVolume(1);
            }
        } catch (\Throwable) {
            // The status is retained for administrators without exposing HTTP details in cron logs.
            \Config::setConfigurationValues(Config::CONTEXT, [
                'plugin_update_last_checked_at' => date('Y-m-d H:i:s'),
                'plugin_update_last_error' => 'Não foi possível verificar atualizações no repositório oficial.',
            ]);
        }

        return true;
    }

    /** @return array<string, string|bool> */
    public static function status(): array
    {
        $current = defined('PLUGIN_DEMANDAS_VERSION') ? (string) PLUGIN_DEMANDAS_VERSION : '0.0.0';
        $latest = trim((string) Config::get('plugin_update_latest_version', ''));
        return [
            'current_version' => $current,
            'latest_version' => $latest,
            'available' => $latest !== '' && version_compare($latest, $current, '>'),
            'release_url' => trim((string) Config::get('plugin_update_release_url', '')),
            'asset_url' => trim((string) Config::get('plugin_update_asset_url', '')),
            'last_checked_at' => trim((string) Config::get('plugin_update_last_checked_at', '')),
            'last_error' => trim((string) Config::get('plugin_update_last_error', '')),
        ];
    }

    /** @return array<string, string|bool> */
    public static function refresh(): array
    {
        $payload = self::latestRelease();
        $tag = trim((string) ($payload['tag_name'] ?? ''));
        $version = preg_replace('/^v/i', '', $tag) ?? '';
        if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('A release oficial não informou uma versão válida.');
        }
        if (!empty($payload['draft']) || !empty($payload['prerelease'])) {
            throw new RuntimeException('A release oficial não está disponível para atualização.');
        }

        $releaseUrl = self::githubUrl((string) ($payload['html_url'] ?? ''));
        $assetUrl = '';
        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url = self::githubUrl((string) ($asset['browser_download_url'] ?? ''));
            if ($url !== '' && preg_match('/^demandas-' . preg_quote($version, '/') . '(?:[-.].+)?\.zip$/i', $name)) {
                $assetUrl = $url;
                break;
            }
        }

        \Config::setConfigurationValues(Config::CONTEXT, [
            'plugin_update_latest_version' => $version,
            'plugin_update_release_url' => $releaseUrl,
            'plugin_update_asset_url' => $assetUrl,
            'plugin_update_last_checked_at' => date('Y-m-d H:i:s'),
            'plugin_update_last_error' => '',
        ]);

        return self::status();
    }

    /** @return array<string, mixed> */
    private static function latestRelease(): array
    {
        $curl = curl_init(self::LATEST_RELEASE_URL);
        if ($curl === false) {
            throw new RuntimeException('A verificação de atualizações não está disponível neste servidor.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GLPI-Demandas/' . (defined('PLUGIN_DEMANDAS_VERSION') ? PLUGIN_DEMANDAS_VERSION : 'unknown'),
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('O repositório oficial não respondeu à verificação.');
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('O repositório oficial retornou uma resposta inválida.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('O repositório oficial retornou uma resposta inválida.');
        }
        return $decoded;
    }

    private static function githubUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, ['github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com', 'release-assets.githubusercontent.com'], true)
            ? $url
            : '';
    }
}
