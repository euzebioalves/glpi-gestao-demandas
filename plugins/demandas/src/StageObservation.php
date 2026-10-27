<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class StageObservation
{
    /** Callers supply ONLY authorized tickets and their current local links. */
    public static function load(object $db, array $groups): array
    {
        $limits = $states = [];
        foreach ($groups as $id => $group) foreach ($group['links'] ?? [] as $link) {
            $limits[$id][(int) $link['openproject_work_package_id']] = IndicatorPolicy::timestamp($link['last_synced_at'] ?? null) ?? 0;
        }
        foreach (array_chunk(array_keys($groups), 300) as $ids) {
            foreach ($db->request([
                'FROM' => 'glpi_plugin_demandas_events',
                'SELECT' => ['tickets_id', 'openproject_work_package_id', 'previous_status', 'new_status', 'date_creation', 'event_type', 'is_success', 'details_json'],
                'WHERE' => ['tickets_id' => $ids, 'event_type' => ['work_package_synchronized', 'legacy_link_reconciled', 'legacy_link_transferred']],
                'ORDER' => ['date_creation ASC', 'id ASC'],
            ]) as $event) {
                $id = (int) $event['tickets_id']; $wp = (int) $event['openproject_work_package_id'];
                if (!isset($limits[$id][$wp]) || IndicatorPolicy::timestamp($event['date_creation']) === null) continue;
                $state = $states[$id][$wp] ?? ['status'=>null, 'since'=>null];
                if (!(int)$event['is_success']) {
                    $details = json_decode((string)($event['details_json'] ?? ''), true) ?: [];
                    $state['failure'] = in_array($details['failure_category'] ?? '', ['access_denied','unavailable'], true) ? $details['failure_category'] : 'sync_failed';
                    $states[$id][$wp] = $state;
                } elseif ($limits[$id][$wp] && IndicatorPolicy::timestamp($event['date_creation']) <= $limits[$id][$wp]) {
                    $states[$id][$wp] = self::advanceObservation($state, $event, $limits[$id][$wp]);
                }
            }
        }
        return $states;
    }

    /** Earliest observation in the current uninterrupted LOCAL stage sequence.
     * Not a claim about the exact transition date in OpenProject.
     */
    public static function observedSince(array $events, string $current, ?string $lastSync): ?string
    {
        $state = ['status'=>null, 'since'=>null];
        $limit = IndicatorPolicy::timestamp($lastSync) ?? 0;
        if (!$limit || $limit > time()) return null;
        foreach ($events as $event) {
            $state = self::advanceObservation($state, $event, $limit);
        }
        return trim($current) !== '' && $state['status'] === trim($current) ? $state['since'] : null;
    }

    public static function advanceObservation(array $state, array $event, int $limit): array
    {
        $date = (string) ($event['date_creation'] ?? '');
        $ts = IndicatorPolicy::timestamp($date) ?? 0;
        if (!$ts || !$limit || $ts > $limit) return $state;
        $next = trim((string) ($event['new_status'] ?? ''));
        if (!IndicatorPolicy::statusKnown($next)) return ['status'=>null, 'since'=>null];
        $previous = trim((string) ($event['previous_status'] ?? ''));
        // A different preceding stage is evidence of a gap, even when the latest status matches.
        $stageKey = static fn(string $status): string => ActionDashboard::stage($status) === 'other'
            ? 'other:' . ActionDashboard::normalized($status) : ActionDashboard::stage($status);
        $newAssociation = in_array($event['event_type'] ?? '', ['legacy_link_reconciled','legacy_link_transferred'], true);
        $since = $newAssociation || $state['status'] === null || $stageKey($next) !== $stageKey($state['status'])
            || ($previous !== '' && $stageKey($previous) !== $stageKey($next)) ? $date : $state['since'];
        return ['status'=>$next, 'since'=>$since];
    }

}
