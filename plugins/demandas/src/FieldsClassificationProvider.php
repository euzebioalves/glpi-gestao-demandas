<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;
use Ticket;

final class FieldsClassificationProvider
{
    /**
     * Finds textual Fields-plugin columns named "Atividade DevOps". The
     * customer can use a different container, so the physical table is
     * discovered from the Fields metadata instead of being hard-coded.
     */
    public static function workPackageLinkFields(): array
    {
        try {
            $db = \DBConnection::getReadConnection();
            $containers = [];
            foreach ($db->request(['FROM' => 'glpi_plugin_fields_containers']) as $container) {
                $itemtypes = json_decode((string) ($container['itemtypes'] ?? '[]'), true);
                if (is_array($itemtypes) && in_array(Ticket::class, $itemtypes, true)) {
                    $containers[(int) $container['id']] = $container;
                }
            }

            $fields = [];
            foreach ($db->request(['FROM' => 'glpi_plugin_fields_fields']) as $field) {
                $container = $containers[(int) ($field['plugin_fields_containers_id'] ?? 0)] ?? null;
                if (!is_array($container)) {
                    continue;
                }
                $label = (string) ($field['label'] ?? $field['name'] ?? '');
                if (self::normalizedLabel($label) !== 'atividadedevops') {
                    continue;
                }
                $containerName = self::identifier((string) ($container['name'] ?? ''), false);
                $fieldName = self::identifier((string) ($field['name'] ?? ''), false);
                $type = (string) ($field['type'] ?? '');
                // A link is normally stored as text/URL. Dropdown fields do
                // not carry an OpenProject URL and are intentionally ignored.
                if ($type === 'dropdown') {
                    continue;
                }
                $fields[] = [
                    'table' => 'glpi_plugin_fields_ticket' . $containerName . 's',
                    'ticket_key' => 'items_id',
                    'itemtype_field' => 'itemtype',
                    'value_field' => 'plugin_fields_' . $fieldName,
                ];
            }
            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }
    public static function fields(): array
    {
        try {
            $db = \DBConnection::getReadConnection();
            $containers = [];
            foreach ($db->request(['FROM' => 'glpi_plugin_fields_containers']) as $container) {
                $itemtypes = json_decode((string) ($container['itemtypes'] ?? '[]'), true);
                if (is_array($itemtypes) && in_array(Ticket::class, $itemtypes, true)) {
                    $containers[(int) $container['id']] = $container;
                }
            }

            if ($containers === []) {
                return [];
            }

            $fields = [];
            foreach ($db->request(['FROM' => 'glpi_plugin_fields_fields']) as $field) {
                $containerId = (int) ($field['plugin_fields_containers_id'] ?? 0);
                if (!isset($containers[$containerId]) || (string) ($field['type'] ?? '') !== 'dropdown') {
                    continue;
                }
                $container = $containers[$containerId];
                $containerName = self::identifier((string) ($container['name'] ?? ''), false);
                $fieldName = self::identifier((string) ($field['name'] ?? ''), false);
                $fieldId = (int) ($field['id'] ?? 0);
                if ($fieldId <= 0) {
                    continue;
                }
                $fields[$fieldId] = [
                    'id' => $fieldId,
                    'label' => (string) ($field['label'] ?? $fieldName),
                    'container_label' => (string) ($container['label'] ?? $containerName),
                    'table' => 'glpi_plugin_fields_ticket' . $containerName . 's',
                    'ticket_key' => 'items_id',
                    'itemtype_field' => 'itemtype',
                    'value_field' => 'plugin_fields_' . $fieldName . 'dropdowns_id',
                    'options_table' => 'glpi_plugin_fields_' . $fieldName . 'dropdowns',
                ];
            }
            uasort($fields, static fn(array $a, array $b): int => strnatcasecmp(
                $a['container_label'] . ' ' . $a['label'],
                $b['container_label'] . ' ' . $b['label']
            ));
            return $fields;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function field(int $fieldId): array
    {
        $field = self::fields()[$fieldId] ?? null;
        if (!is_array($field)) {
            throw new RuntimeException('O campo selecionado não foi encontrado no plugin Fields ou não é uma lista suspensa de chamados.');
        }
        return $field;
    }

    public static function options(int $fieldId): array
    {
        $field = self::field($fieldId);
        try {
            $options = [];
            foreach (\DBConnection::getReadConnection()->request([
                'FROM' => $field['options_table'],
                'ORDER' => 'completename ASC, name ASC',
            ]) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) continue;
                $label = trim((string) ($row['completename'] ?? ''));
                if ($label === '') $label = trim((string) ($row['name'] ?? ''));
                $options[(string) $id] = $label !== '' ? $label : ('Opção #' . $id);
            }
            return $options;
        } catch (\Throwable $exception) {
            throw new RuntimeException('Não foi possível carregar as opções do campo selecionado no plugin Fields.', 0, $exception);
        }
    }

    public static function validate(int $fieldId): void
    {
        $field = self::field($fieldId);
        try {
            $db = \DBConnection::getReadConnection();
            $iterator = $db->request([
                'SELECT' => [$field['ticket_key'], $field['itemtype_field'], $field['value_field']],
                'FROM' => $field['table'],
                'LIMIT' => 1,
            ]);
            foreach ($iterator as $_row) {
                break;
            }
            self::options($fieldId);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'A estrutura do campo selecionado no plugin Fields não pôde ser validada. Verifique se o contêiner está ativo e aplicado a chamados.',
                0,
                $exception
            );
        }
    }

    public static function value(Ticket $ticket, int $fieldId): string
    {
        $field = self::field($fieldId);
        try {
            foreach (\DBConnection::getReadConnection()->request([
                'SELECT' => [$field['value_field']],
                'FROM' => $field['table'],
                'WHERE' => [
                    $field['ticket_key'] => $ticket->getID(),
                    $field['itemtype_field'] => Ticket::class,
                ],
                'LIMIT' => 1,
            ]) as $row) {
                return (string) ($row[$field['value_field']] ?? '');
            }
            return '';
        } catch (\Throwable $exception) {
            throw new RuntimeException('Não foi possível ler o campo de classificação selecionado no plugin Fields.', 0, $exception);
        }
    }

    private static function identifier(string $value, bool $table): string
    {
        $pattern = $table ? '/^glpi_[a-z0-9_]+$/' : '/^[a-z][a-z0-9_]*$/';
        if (!preg_match($pattern, $value)) {
            throw new RuntimeException('O plugin Fields retornou um identificador técnico inválido.');
        }
        return $value;
    }

    private static function normalizedLabel(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
