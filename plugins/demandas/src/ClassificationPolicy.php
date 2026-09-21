<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;
use Ticket;

final class ClassificationPolicy
{
    public static function source(): array
    {
        $decoded = json_decode((string) Config::get('classification_source_json', '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function rules(): array
    {
        $decoded = json_decode((string) Config::get('classification_rules_json', '[]'), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    public static function validateSource(array $source): void
    {
        $mode = (string) ($source['mode'] ?? 'none');
        if ($mode === 'none') {
            return;
        }
        if ($mode === 'ticket_field') {
            self::identifier((string) ($source['field'] ?? ''), false);
            return;
        }
        if ($mode === 'fields_plugin') {
            FieldsClassificationProvider::validate((int) ($source['field_id'] ?? 0));
            return;
        }
        if ($mode === 'related_table') {
            self::identifier((string) ($source['table'] ?? ''), true);
            self::identifier((string) ($source['ticket_key'] ?? ''), false);
            self::identifier((string) ($source['value_field'] ?? ''), false);
            if (trim((string) ($source['itemtype_field'] ?? '')) !== '') {
                self::identifier((string) $source['itemtype_field'], false);
            }
            try {
                $iterator = \DBConnection::getReadConnection()->request([
                    'SELECT' => [(string) $source['ticket_key'], (string) $source['value_field']],
                    'FROM' => (string) $source['table'],
                    'LIMIT' => 1,
                ]);
                foreach ($iterator as $_row) {
                    break;
                }
            } catch (\Throwable $exception) {
                throw new RuntimeException('A tabela ou as colunas da origem avançada não puderam ser validadas.', 0, $exception);
            }
            return;
        }
        throw new RuntimeException('A origem da classificação configurada é inválida.');
    }

    public static function resolve(Ticket $ticket): array
    {
        $source = self::source();
        self::validateSource($source);
        $mode = (string) ($source['mode'] ?? 'none');
        if ($mode === 'none') {
            return ['value' => '', 'label' => 'Não configurada'];
        }

        if ($mode === 'ticket_field') {
            $field = self::identifier((string) ($source['field'] ?? ''), false);
            $value = (string) ($ticket->fields[$field] ?? '');
            return ['value' => $value, 'label' => self::ruleLabel($value)];
        }

        if ($mode === 'fields_plugin') {
            $value = FieldsClassificationProvider::value($ticket, (int) ($source['field_id'] ?? 0));
            return ['value' => $value, 'label' => self::ruleLabel($value)];
        }

        if ($mode === 'related_table') {
            $table = self::identifier((string) ($source['table'] ?? ''), true);
            $ticketKey = self::identifier((string) ($source['ticket_key'] ?? 'tickets_id'), false);
            $valueField = self::identifier((string) ($source['value_field'] ?? ''), false);
            $where = [$ticketKey => $ticket->getID()];
            $itemtypeField = trim((string) ($source['itemtype_field'] ?? ''));
            if ($itemtypeField !== '') {
                $where[self::identifier($itemtypeField, false)] = Ticket::class;
            }
            try {
                $iterator = \DBConnection::getReadConnection()->request([
                    'SELECT' => [$valueField],
                    'FROM' => $table,
                    'WHERE' => $where,
                    'LIMIT' => 1,
                ]);
                foreach ($iterator as $row) {
                    $value = (string) ($row[$valueField] ?? '');
                    return ['value' => $value, 'label' => self::ruleLabel($value)];
                }
            } catch (\Throwable $exception) {
                throw new RuntimeException('Não foi possível ler a classificação configurada: ' . $exception->getMessage(), 0, $exception);
            }
            return ['value' => '', 'label' => 'Sem classificação'];
        }

        throw new RuntimeException('A origem da classificação configurada é inválida.');
    }

    public static function filterTypes(Ticket $ticket, array $types): array
    {
        $allowed = self::allowedTypeNames($ticket);
        if ($allowed === null) {
            return $types;
        }
        return array_values(array_filter($types, static function (array $type) use ($allowed): bool {
            $name = self::normalize((string) ($type['name'] ?? ''));
            return in_array($name, $allowed, true);
        }));
    }

    public static function assertTypeAllowed(Ticket $ticket, array $type): void
    {
        $allowed = self::allowedTypeNames($ticket);
        if ($allowed === null) {
            return;
        }
        if (!in_array(self::normalize((string) ($type['name'] ?? '')), $allowed, true)) {
            $classification = self::resolve($ticket);
            throw new RuntimeException(sprintf(
                'O tipo de Work Package selecionado não é permitido para a classificação "%s".',
                $classification['label'] ?: $classification['value']
            ));
        }
    }

    private static function allowedTypeNames(Ticket $ticket): ?array
    {
        if ((string) (self::source()['mode'] ?? 'none') === 'none') {
            return null;
        }
        $classification = self::resolve($ticket);
        foreach (self::rules() as $rule) {
            if ((string) ($rule['value'] ?? '') === $classification['value']) {
                return array_values(array_unique(array_filter(array_map(
                    static fn(mixed $name): string => self::normalize((string) $name),
                    (array) ($rule['allowed_type_names'] ?? [])
                ))));
            }
        }
        // Fail closed: uma classificação sem regra não pode criar qualquer tipo.
        return [];
    }

    private static function ruleLabel(string $value): string
    {
        foreach (self::rules() as $rule) {
            if ((string) ($rule['value'] ?? '') === $value) {
                return trim((string) ($rule['label'] ?? '')) ?: $value;
            }
        }
        return $value !== '' ? $value : 'Sem classificação';
    }

    private static function identifier(string $value, bool $table): string
    {
        $pattern = $table ? '/^glpi_[a-z0-9_]+$/' : '/^[a-z][a-z0-9_]*$/';
        if (!preg_match($pattern, $value)) {
            throw new RuntimeException('A configuração da origem da classificação contém um identificador inválido.');
        }
        return $value;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
