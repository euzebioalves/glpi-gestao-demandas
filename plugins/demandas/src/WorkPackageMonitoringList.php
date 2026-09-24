<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

/** Shared request normalization and pagination for the two monitoring views. */
final class WorkPackageMonitoringList
{
    public const PAGE_SIZES = [25, 50, 100, 200];

    public static function filters(array $input): array
    {
        $filters = [];
        foreach (['status', 'ticket', 'customer', 'responsible'] as $key) {
            $filters[$key] = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        }
        $ticket = (int) $filters['ticket'];
        $filters['ticket'] = $ticket > 0 ? (string) $ticket : '';
        return $filters;
    }

    public static function pageSize(array $input): int
    {
        $size = filter_var($input['per_page'] ?? 25, FILTER_VALIDATE_INT);
        return in_array($size, self::PAGE_SIZES, true) ? $size : 25;
    }

    /** Slice only after filtering; exports deliberately use the unsliced rows. */
    public static function paginate(array $rows, array $input): array
    {
        $size = self::pageSize($input);
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $size));
        $requested = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
        $page = max(1, min($pages, $requested === false ? 1 : $requested));
        $offset = ($page - 1) * $size;
        return [
            'rows' => array_slice($rows, $offset, $size),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $size,
            'first' => $total === 0 ? 0 : $offset + 1,
            'last' => min($total, $offset + $size),
        ];
    }
}
