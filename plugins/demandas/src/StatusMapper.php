<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class StatusMapper
{
    public static function phases(): array
    {
        try {
            $items = json_decode((string) Config::get('public_phases_json', '[]'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $items = [];
        }
        $phases = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($id !== '' && $name !== '') $phases[$id] = $name;
        }
        return $phases;
    }

    public static function configuredMapping(): array
    {
        try {
            $mapping = json_decode((string) Config::get('status_mapping_json', '{}'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($mapping) ? $mapping : [];
        } catch (\JsonException) {
            return [];
        }
    }

    public static function configuredRules(): array
    {
        try {
            $rules = json_decode((string) Config::get('status_rules_json', '{}'), true, 512, JSON_THROW_ON_ERROR);
            return is_array($rules) ? $rules : [];
        } catch (\JsonException) {
            return [];
        }
    }

    public static function rule(?int $statusId): array
    {
        if ($statusId === null) return [];
        $rules = self::configuredRules();
        return is_array($rules[(string) $statusId] ?? null) ? $rules[(string) $statusId] : [];
    }

    public static function publicPhase(
        string $status,
        ?int $statusId = null,
        string $fallback = 'Em análise'
    ): string {
        if ($statusId !== null) {
            $mapping = self::configuredMapping();
            $key = (string) $statusId;
            if (array_key_exists($key, $mapping)) {
                $configured = (string) $mapping[$key];
                $phases = self::phases();
                if (array_key_exists($configured, $phases)) return $phases[$configured];
                // Compatibilidade com configurações anteriores que armazenavam o nome.
                return in_array($configured, $phases, true) ? $configured : $fallback;
            }
        }

        return self::defaultPhase($status, $fallback);
    }

    public static function defaultPhase(string $status, string $fallback = 'Em análise'): string
    {
        $normalized = self::normalize($status);
        $rules = [
            'cancel' => 'Não seguirá para implementação',
            'canceled' => 'Não seguirá para implementação',
            'conclu' => 'Implementação concluída',
            'fechad' => 'Implementação concluída',
            'closed' => 'Implementação concluída',
            'resolvid' => 'Implementação concluída',
            'resolved' => 'Implementação concluída',
            'homolog' => 'Aguardando homologação',
            'teste' => 'Em validação interna',
            'testing' => 'Em validação interna',
            'validacao interna' => 'Em validação interna',
            'revisao' => 'Em validação interna',
            'desenvolvimento' => 'Em desenvolvimento',
            'development' => 'Em desenvolvimento',
            'em andamento' => 'Em desenvolvimento',
            'priorizad' => 'Planejada',
            'planejad' => 'Planejada',
            'especificada' => 'Aguardando priorização',
            'specified' => 'Aguardando priorização',
            'especificacao' => 'Em especificação',
            'specification' => 'Em especificação',
            'analise' => 'Em análise',
            'analysis' => 'Em análise',
            'novo' => 'Em análise',
            'nova' => 'Em análise',
            'new' => 'Em análise',
        ];

        foreach ($rules as $needle => $phase) {
            if (str_contains($normalized, $needle)) {
                return $phase;
            }
        }

        return $fallback;
    }

    private static function normalize(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $normalized = mb_strtolower($transliterated !== false ? $transliterated : $value);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $normalized));
    }
}
