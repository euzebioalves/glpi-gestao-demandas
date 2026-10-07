<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Search\Provider\SQLProvider;
use Search;
use Session;
use Ticket;

/** Native Ticket search integration; reads local links only. */
final class TicketSearch
{
    // Stable identifier: saved searches and display preferences persist this ID.
    public const WORK_PACKAGE = 925001;
    private const TABLE = 'glpi_plugin_demandas_links';
    private const FIELD = 'openproject_work_package_id';

    public static function options(string $itemtype): array
    {
        if ($itemtype !== Ticket::class || !self::canView()) {
            return [];
        }

        return [[
            'id' => self::WORK_PACKAGE,
            'name' => 'Work Package',
            'table' => self::TABLE,
            'field' => self::FIELD,
            'datatype' => 'string',
            'searchtype' => ['contains', 'equals', 'notequals'],
            'searchequalsonfield' => true,
            'forcegroupby' => true,
            'joinparams' => ['jointype' => 'child'],
            'massiveaction' => false,
            'nometa' => true,
        ]];
    }

    private static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0 && Profile::has(Profile::VIEW_TECHNICAL);
    }

    /** Filter tickets, not the display join: keep all WPs when only one matches. */
    public static function criteria(bool $not, string $value, string $searchtype): array
    {
        if (!self::canView()) {
            return [new QueryExpression('false')];
        }

        $where = [self::FIELD => ['>', 0]];
        // GLPI already inverts $not for notcontains, but not for notequals.
        if ($searchtype === 'notequals') {
            $not = !$not;
        }
        if ($searchtype === 'empty' || in_array(strtolower($value), ['null', '^$'], true)) {
            $not = !$not;
        } elseif (in_array($searchtype, ['equals', 'notequals'], true)) {
            // Do not let MySQL coerce malformed strings to valid numeric IDs.
            $where[] = ctype_digit($value) && (int) $value > 0
                ? [self::FIELD => (int) $value]
                : new QueryExpression('false');
        } elseif (in_array($searchtype, ['contains', 'notcontains'], true)) {
            // GLPI handles quoting, anchors (^/$) and wildcard escaping.
            $where[] = new QueryExpression(SQLProvider::makeTextCriteria('`' . self::FIELD . '`', $value, false, ''));
        } else {
            return [new QueryExpression('false')];
        }

        return ['glpi_tickets.id' => [$not ? 'NOT IN' : 'IN', new QuerySubQuery([
            'SELECT' => 'tickets_id',
            'FROM' => self::TABLE,
            'WHERE' => $where,
        ])]];
    }

    public static function render(array $data, string $key): string
    {
        $ticketId = (int) ($data['id'] ?? 0);
        if (!self::canView() || $ticketId <= 0 || !(new Ticket())->can($ticketId, READ)) {
            // An empty string makes GLPI fall back to its default raw formatter.
            return ' ';
        }

        $ids = [];
        foreach ($data[$key] ?? [] as $index => $entry) {
            if (!is_int($index) || !is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['name'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return ' ';
        }
        $base = rtrim((string) Config::get('openproject_external_url', ''), '/');
        $parts = parse_url($base);
        $safeUrl = is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']);
        $html = in_array(Search::$output_type, [Search::HTML_OUTPUT, Search::GLOBAL_SEARCH], true);
        return implode(' | ', array_map(static function (int $id) use ($base, $safeUrl, $html): string {
            if (!$safeUrl || !$html) {
                return (string) $id;
            }
            $url = htmlspecialchars($base . '/work_packages/' . $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\">{$id}</a>";
        }, $ids));
    }
}
