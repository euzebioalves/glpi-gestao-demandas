<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DateTimeImmutable;
use Search;
use Ticket;
use User;

final class TicketLogService
{
    public static function page(int $ticketId, int $page, int $perPage): array
    {
        $groups = self::groups($ticketId);
        $total = count($groups);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'items' => array_slice($groups, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private static function groups(int $ticketId): array
    {
        $rows = self::ticketRows($ticketId);
        foreach (self::fieldsTargets($ticketId) as $target) {
            foreach (self::rowsForTarget($target['itemtype'], (int) $target['id']) as $id => $row) {
                $rows[$id] = $row;
            }
        }

        usort($rows, static fn(array $a, array $b): int =>
            [(string) ($a['date_mod'] ?? ''), (int) ($a['id'] ?? 0)]
            <=> [(string) ($b['date_mod'] ?? ''), (int) ($b['id'] ?? 0)]
        );

        $groups = [];
        $users = [];
        foreach ($rows as $row) {
            $date = (string) ($row['date_mod'] ?? '');
            if ($date === '') continue;
            $key = $date;
            $userId = (int) ($row['users_id'] ?? 0);
            $recordedUser = self::cleanValue((string) ($row['user_name'] ?? ''));
            $userKey = $recordedUser !== '' ? 'name:' . $recordedUser : 'id:' . $userId;
            if (!isset($users[$userKey])) {
                $users[$userKey] = $recordedUser !== '' ? $recordedUser : self::userName($userId);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => self::datePtBr($date),
                    'users' => [],
                    'changes' => [],
                    'ids' => [],
                ];
            }
            $groups[$key]['users'][$users[$userKey]] = true;
            $groups[$key]['ids'][] = (int) ($row['id'] ?? 0);
            $groups[$key]['changes'][] = self::change($row);
        }

        foreach ($groups as &$group) {
            $meaningful = array_values(array_filter(
                $group['changes'],
                static fn(array $change): bool => !$change['technical']
            ));
            if ($meaningful !== []) $group['changes'] = $meaningful;
            $group['users'] = array_keys($group['users']);
            $group['id'] = implode(', ', $group['ids']);
            $group['record_count'] = count($group['ids']);
            unset($group['ids']);
        }
        unset($group);

        return array_values($groups);
    }

    private static function ticketRows(int $ticketId): array
    {
        return self::rowsForTarget(Ticket::class, $ticketId);
    }

    private static function rowsForTarget(string $itemtype, int $itemId): array
    {
        $rows = [];
        try {
            foreach (\DBConnection::getReadConnection()->request([
                'FROM' => 'glpi_logs',
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemId],
                'ORDER' => 'date_mod ASC, id ASC',
            ]) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        } catch (\Throwable) {
            // Um histórico complementar indisponível não impede o log principal.
        }
        return $rows;
    }

    private static function fieldsTargets(int $ticketId): array
    {
        $targets = [];
        $tables = [];
        foreach (FieldsClassificationProvider::fields() as $field) {
            $tables[(string) $field['table']] = $field;
        }
        foreach ($tables as $table => $field) {
            try {
                foreach (\DBConnection::getReadConnection()->request([
                    'SELECT' => ['id'],
                    'FROM' => $table,
                    'WHERE' => [
                        (string) $field['ticket_key'] => $ticketId,
                        (string) $field['itemtype_field'] => Ticket::class,
                    ],
                ]) as $row) {
                    $suffix = substr($table, strlen('glpi_plugin_fields_'));
                    $targets[] = [
                        'itemtype' => 'PluginFields' . str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix))),
                        'id' => (int) $row['id'],
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return $targets;
    }

    private static function change(array $row): array
    {
        $itemtype = (string) ($row['itemtype'] ?? Ticket::class);
        $fieldsMetadata = self::fieldsMetadata($itemtype);
        $field = $fieldsMetadata['label'] ?? self::fieldLabel($itemtype, (int) ($row['id_search_option'] ?? 0));
        $old = self::cleanValue((string) ($row['old_value'] ?? ''));
        $new = self::cleanValue((string) ($row['new_value'] ?? ''));
        if ($fieldsMetadata !== null) {
            $old = $fieldsMetadata['options'][$old] ?? $old;
            $new = $fieldsMetadata['options'][$new] ?? $new;
        } else {
            $old = self::resolveValue($itemtype, (int) ($row['id_search_option'] ?? 0), $old);
            $new = self::resolveValue($itemtype, (int) ($row['id_search_option'] ?? 0), $new);
        }
        $technical = in_array(self::normalize($field), [
            'ultima atualizacao',
            'last update',
            'geral',
            'registro do chamado',
            'leve em conta o tempo',
        ], true);

        if ($old !== '' && $new !== '') {
            $description = $old === $new ? 'Registro atualizado' : $old . ' → ' . $new;
        } elseif ($new !== '') {
            $description = 'Definido como ' . $new;
        } elseif ($old !== '') {
            $description = 'Removido (valor anterior: ' . $old . ')';
        } else {
            $description = 'Registro atualizado';
        }

        return ['field' => $field, 'description' => $description, 'technical' => $technical];
    }

    private static function fieldsMetadata(string $itemtype): ?array
    {
        $source = ClassificationPolicy::source();
        if ((string) ($source['mode'] ?? '') !== 'fields_plugin') return null;
        try {
            $field = FieldsClassificationProvider::field((int) ($source['field_id'] ?? 0));
            $suffix = substr((string) $field['table'], strlen('glpi_plugin_fields_'));
            $expected = 'PluginFields' . str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));
            if ($itemtype !== $expected) return null;
            return [
                'label' => (string) $field['label'],
                'options' => FieldsClassificationProvider::options((int) $source['field_id']),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function fieldLabel(string $itemtype, int $optionId): string
    {
        $option = self::searchOption($itemtype, $optionId);
        if (isset($option['name'])) return trim(strip_tags((string) $option['name']));
        return $optionId > 0 ? 'Campo #' . $optionId : 'Registro do chamado';
    }

    private static function resolveValue(string $itemtype, int $optionId, string $value): string
    {
        if ($value === '' || !ctype_digit($value)) return $value;
        try {
            $option = self::searchOption($itemtype, $optionId);
            $field = (string) ($option['field'] ?? '');
            $id = (int) $value;
            $ticketResolvers = [
                'status' => 'getStatus',
                'priority' => 'getPriorityName',
                'urgency' => 'getUrgencyName',
                'impact' => 'getImpactName',
            ];
            if ($itemtype === Ticket::class && isset($ticketResolvers[$field])) {
                $method = $ticketResolvers[$field];
                if (is_callable([Ticket::class, $method])) {
                    $resolved = trim(strip_tags((string) Ticket::$method($id)));
                    if ($resolved !== '') return $resolved;
                }
            }
            $linkedType = (string) ($option['itemlink'] ?? '');
            if ($linkedType !== '' && class_exists($linkedType)) {
                $linked = new $linkedType();
                if ($linked->getFromDB($id) && method_exists($linked, 'getName')) {
                    $resolved = trim((string) $linked->getName());
                    if ($resolved !== '') return $resolved;
                }
            }
        } catch (\Throwable) {
            // Mantém o valor original quando não houver um resolvedor compatível.
        }
        return $value;
    }

    private static function searchOption(string $itemtype, int $optionId): array
    {
        static $cache = [];
        $key = $itemtype . ':' . $optionId;
        if (isset($cache[$key])) return $cache[$key];
        try {
            if (class_exists($itemtype)) {
                $options = Search::getOptions($itemtype);
                if (isset($options[$optionId]) && is_array($options[$optionId])) {
                    return $cache[$key] = $options[$optionId];
                }
            }
        } catch (\Throwable) {
            // Retorna uma opção vazia.
        }
        return $cache[$key] = [];
    }

    private static function cleanValue(string $value): string
    {
        $value = preg_replace('/<br\s*\/?>/i', ' ', $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        return preg_match('/^(n\/a|nao aplicavel)(\s*\(\d+\))?$/', self::normalize($value)) === 1 ? '' : $value;
    }

    private static function userName(int $userId): string
    {
        if ($userId <= 0) return 'Sistema';
        $user = new User();
        if ($user->getFromDB($userId)) {
            $name = trim((string) $user->getFriendlyName());
            if ($name !== '') return $name;
        }
        return 'Usuário #' . $userId;
    }

    private static function datePtBr(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $date;
        }
    }

    private static function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value)));
        return $ascii === false ? mb_strtolower(trim($value)) : $ascii;
    }
}
