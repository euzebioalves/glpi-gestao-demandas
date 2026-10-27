<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Read-only operational view. Receives only tickets authorized by the dashboard. */
final class ActionDashboard
{
    public const STAGES = [
        'specification' => 'Aguardando especificação', 'ready' => 'Prontos para desenvolvimento',
        'development' => 'Em desenvolvimento', 'validation' => 'Aguardando validação',
        'blocked' => 'Bloqueados ou em dúvida', 'delivered' => 'Entregue', 'other' => 'Outras situações',
    ];
    public const REASONS = [
        'stale' => 'Dados da integração desatualizados',
        'unknown' => 'Status da WP não informado',
        'sync_failed' => 'Última tentativa de sincronização falhou',
        'closed_pending' => 'Chamado encerrado com WP não entregue',
        'blocked' => 'WP bloqueada ou em dúvida',
        'all_delivered' => 'Todas as WPs entregues — conferir encerramento',
        'partial' => 'Entrega parcial — conferir pendências',
        'no_wp' => 'Sem WP vinculada',
        'unassigned' => 'Responsável da WP não informado',
        'aging' => 'Etapa observada acima do prazo de atenção',
    ];
    public const REVIEW_REASONS = ['all_delivered', 'partial', 'closed_pending'];

    public static function normalized(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    }

    public static function stage(string $status): string
    {
        if (IndicatorPolicy::delivered($status)) return 'delivered';
        return match (self::normalized($status)) {
            'novo', 'em especificacao' => 'specification',
            'a desenvolver', 'planejado', 'a ser planejado' => 'ready',
            'em desenvolvimento', 'em andamento', 'retrabalho' => 'development',
            'desenvolvido', 'aguardando testes', 'em testes', 'testado', 'pronto para entrega' => 'validation',
            'bloqueado', 'em duvida' => 'blocked',
            default => 'other',
        };
    }

    public static function observedSince(array $events, string $current, ?string $lastSync): ?string
    {
        return StageObservation::observedSince($events, $current, $lastSync);
    }

    public static function enrich(array $groups, int $attentionDays, bool $review): array
    {
        if (!$groups) return [];
        $db = \DBConnection::getReadConnection();
        $history = StageObservation::load($db, array_map(static fn($group)=>['links'=>$group['work_packages']], $groups));
        $owners = [];
        foreach (array_chunk(array_keys($groups), 300) as $ids) {
            foreach ($db->request([
                'SELECT'=>['glpi_tickets_users'=>['tickets_id','users_id'], 'glpi_users'=>['name','realname','firstname']],
                'FROM'=>'glpi_tickets_users',
                'LEFT JOIN'=>['glpi_users'=>['ON'=>['glpi_users'=>'id','glpi_tickets_users'=>'users_id']]],
                'WHERE'=>['glpi_tickets_users.tickets_id'=>$ids,'glpi_tickets_users.type'=>\CommonITILActor::ASSIGN],
            ]) as $owner) {
                $name = trim(($owner['firstname'] ?? '') . ' ' . ($owner['realname'] ?? '')) ?: ($owner['name'] ?? 'Usuário indisponível');
                $owners[(int)$owner['tickets_id']][(int)$owner['users_id']] = $name;
            }
        }
        foreach ($groups as $id => &$group) {
            $group['glpi_owners'] = $owners[$id] ?? [];
            $group['op_owners'] = [];
            $group['stages'] = [];
            $group['attention'] = [];
            $group['stage_age'] = null;
            foreach ($group['work_packages'] as &$wp) {
                $wpId = (int)$wp['openproject_work_package_id'];
                $details = json_decode((string)($wp['work_package_details_json'] ?? ''), true);
                $wp['responsible'] = is_array($details) && is_string($details['responsible'] ?? null) ? trim($details['responsible']) : '';
                $wp['assignee'] = is_array($details) && is_string($details['assignee'] ?? null) ? trim($details['assignee']) : '';
                $wp['stage'] = self::stage((string)$wp['openproject_status']);
                $state = $history[$id][$wpId] ?? ['status'=>null,'since'=>null];
                $wp['quality'] = $state['failure'] ?? IndicatorPolicy::freshness($wp['last_synced_at'] ?? null);
                if (isset($state['failure'])) $group['attention']['sync_failed'] = true;
                $wp['observed_since'] = trim((string)$wp['openproject_status']) !== '' && $state['status'] === trim((string)$wp['openproject_status']) ? $state['since'] : null;
                // The last successful sync is the upper observation bound, never "now" for stale data.
                $wp['stage_days'] = $wp['observed_since'] === null ? null : max(0, (int)floor((strtotime($wp['last_synced_at']) - strtotime($wp['observed_since'])) / 86400));
                $group['stages'][$wp['stage']] = true;
                if ($wp['responsible'] !== '') $group['op_owners'][$wp['responsible']] = $wp['responsible'];
                if ($wp['sync_stale']) $group['attention']['stale'] = true;
                if (!IndicatorPolicy::statusKnown((string)$wp['openproject_status'])) $group['attention']['unknown'] = true;
                if ($group['is_open'] && $wp['stage'] !== 'delivered') {
                    if ($wp['stage'] === 'blocked') $group['attention']['blocked'] = true;
                    if ($wp['responsible'] === '') $group['attention']['unassigned'] = true;
                    if ($wp['stage_days'] !== null) {
                        $group['stage_age'] = max($group['stage_age'] ?? 0, $wp['stage_days']);
                        if ($wp['stage_days'] >= $attentionDays) $group['attention']['aging'] = true;
                    }
                }
            }
            unset($wp);
            if ($group['is_open'] && !$group['work_packages']) $group['attention']['no_wp'] = true;
            if ($review && $group['work_packages']) {
                if ($group['is_open'] && $group['delivery_state'] !== 'none') $group['attention'][$group['delivery_state']] = true;
                if (!$group['is_open'] && $group['delivery_state'] !== 'all_delivered') $group['attention']['closed_pending'] = true;
            }
            $group['attention'] = array_intersect_key(self::REASONS, $group['attention']);
        }
        unset($group);
        return $groups;
    }

    public static function summary(array $groups): array
    {
        $counts = array_fill_keys(array_keys(self::REASONS), 0);
        $stages = [];
        foreach (self::STAGES as $key=>$label) $stages[$key] = ['label'=>$label,'count'=>0,'known'=>0,'unknown'=>0,'max_days'=>null,'oldest'=>[]];
        $attention = [];
        $owners = [];
        foreach ($groups as $group) {
            if ($group['attention']) $attention[] = $group;
            foreach ($group['attention'] as $key=>$_) $counts[$key]++;
            foreach ($group['op_owners'] ?: ['__empty__'=>'Não informado'] as $key=>$name) $owners[$key] ??= ['label'=>$name,'value'=>$key,'count'=>0];
            foreach ($group['op_owners'] ?: ['__empty__'=>'Não informado'] as $key=>$_) $owners[$key]['count']++;
            if (!$group['is_open']) continue;
            foreach (array_keys($group['stages']) as $stage) {
                $stages[$stage]['count']++;
                $days = array_column(array_filter($group['work_packages'], static fn($wp)=>$wp['stage']===$stage), 'stage_days');
                $known = array_values(array_filter($days, static fn($v)=>$v!==null));
                if (count($known) < count($days)) $stages[$stage]['unknown']++;
                if ($known) {
                    $max = max($known);
                    $stages[$stage]['known']++;
                    $stages[$stage]['max_days'] = max($stages[$stage]['max_days'] ?? 0, $max);
                    $stages[$stage]['oldest'][] = ['id'=>$group['ticket_id'],'name'=>$group['ticket_name'],'days'=>$max];
                }
            }
        }
        usort($attention, static fn($a,$b)=>array_search(array_key_first($a['attention']),array_keys(self::REASONS),true)<=>array_search(array_key_first($b['attention']),array_keys(self::REASONS),true) ?: ($b['stage_age']??-1)<=>($a['stage_age']??-1) ?: $b['age_days']<=>$a['age_days'] ?: $a['ticket_id']<=>$b['ticket_id']);
        foreach ($stages as &$stage) {
            usort($stage['oldest'],static fn($a,$b)=>$b['days']<=>$a['days'] ?: $a['id']<=>$b['id']);
            $stage['oldest'] = array_slice($stage['oldest'],0,5);
        }
        unset($stage);
        usort($owners,static fn($a,$b)=>$b['count']<=>$a['count'] ?: strcmp($a['label'],$b['label']));
        return ['counts'=>$counts,'attention'=>$attention,'stages'=>$stages,'owners'=>$owners];
    }
}
