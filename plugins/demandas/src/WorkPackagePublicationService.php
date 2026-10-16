<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

use RuntimeException;
use Session;
use Ticket;

/** Explicit, resumable personal-token reads. Only complete results are persisted. */
final class WorkPackagePublicationService
{
    private const SESSION_KEY = 'demandas_publication_job';

    public static function checkAccess(int $ticketId, int $wpId, bool $refresh): array
    {
        Session::checkLoginUser();
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        $ticket = new Ticket();
        if ($ticketId <= 0 || $wpId <= 0 || !$ticket->getFromDB($ticketId)
            || !in_array((int) $ticket->fields['entities_id'], array_map('intval', Session::getActiveEntities()), true)
            || !$ticket->can($ticketId, READ)) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        if ($refresh) {
            Profile::checkRight(Profile::SYNC_WORK_PACKAGE);
            if (!$ticket->can($ticketId, UPDATE)) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        $link = TicketDemand::findByWorkPackage($wpId, $ticketId);
        if ($link === null) throw new \Glpi\Exception\Http\NotFoundHttpException();
        return $link;
    }

    public static function versions(array $report): string
    {
        $versions = [];
        foreach ($report['tasks'] ?? [] as $task) foreach ($task['releases'] ?? [] as $release) {
            $label = ($release['environment'] ?? 'Não informado') . ': ' . ($release['version'] ?? '');
            $versions[$label] = true;
        }
        return implode(' | ', array_keys($versions));
    }

    /** Recognize publication statements, never PR numbers or generic version mentions. */
    public static function parseComment(string $text): array
    {
        $text = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>|</p>~i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['**', '__'], '', $text);
        preg_match_all('~Inclu[ií]da\s+na\s+build\s+(v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?)(?![0-9A-Za-z.-])([^\r\n]{0,180})~iu', $text, $matches, PREG_SET_ORDER);
        $result = [];
        foreach ($matches as $match) {
            $context = mb_strtolower($match[2]);
            $environment = str_contains($context, 'homologa') ? 'Homologação'
                : (preg_match('/produ[çc][ãa]o/u', $context) ? 'Produção' : 'Ambiente não informado');
            $result[] = ['version' => $match[1], 'environment' => $environment];
        }
        return $result;
    }

    public function start(array $link): array
    {
        $wpId = (int) $link['openproject_work_package_id'];
        $wp = OpenProjectClient::forCurrentUser()->getWorkPackage($wpId);
        if ((int) ($wp['id'] ?? 0) !== $wpId) throw new RuntimeException('Identidade inválida.');
        $ids = [];
        foreach ($wp['_links']['children'] ?? [] as $child) {
            $id = self::wpId((string) ($child['href'] ?? ''));
            if ($id && $id !== $wpId) $ids[$id] = 'Subtarefa';
        }
        $_SESSION[self::SESSION_KEY] = [
            'id' => bin2hex(random_bytes(16)), 'expires' => time() + 900,
            'user' => (int) Session::getLoginUserID(), 'token_hash' => $this->identityHash(),
            'link_id' => (int) $link['id'], 'ticket' => (int) $link['tickets_id'], 'wp' => $wpId,
            'previous' => (string) ($link['release_details_json'] ?? ''),
            'phase' => 'relations', 'page' => 1, 'seen' => [], 'ids' => $ids, 'tasks' => [],
        ];
        return $this->progress($_SESSION[self::SESSION_KEY]);
    }

    public function step(array $link, string $jobId): array
    {
        $job = $_SESSION[self::SESSION_KEY] ?? [];
        if (!hash_equals((string) ($job['id'] ?? ''), $jobId) || $jobId === ''
            || ($job['expires'] ?? 0) < time() || ($job['user'] ?? 0) !== (int) Session::getLoginUserID()
            || ($job['link_id'] ?? 0) !== (int) $link['id'] || ($job['ticket'] ?? 0) !== (int) $link['tickets_id']
            || ($job['wp'] ?? 0) !== (int) $link['openproject_work_package_id']
            || !hash_equals((string) ($job['token_hash'] ?? ''), $this->identityHash())) {
            throw new RuntimeException('Consulta expirada ou alterada. Reinicie a consulta.');
        }
        if ($job['phase'] === 'done') return $this->progress($job);
        $client = OpenProjectClient::forCurrentUser();
        if ($job['phase'] === 'relations') {
            $collection = $client->getPublicationPage('relations', $job['wp'], $job['page']);
            foreach ($this->elements($collection) as $relation) {
                $from = self::wpId((string) ($relation['_links']['from']['href'] ?? ''));
                $to = self::wpId((string) ($relation['_links']['to']['href'] ?? ''));
                if ($from !== $job['wp'] && $to !== $job['wp']) throw new RuntimeException('Relação inconsistente.');
                $id = $from === $job['wp'] ? $to : $from;
                if ($id && $id !== $job['wp']) $job['ids'][$id] ??= 'Relação direta';
            }
            if (count($job['ids']) > 500) throw new RuntimeException('Limite de tarefas excedido.');
            if ($this->finished($collection, $job)) {
                $job['phase'] = 'task'; $job['page'] = 1; $job['seen'] = [];
            }
        } elseif ($job['phase'] === 'task') {
            $remaining = array_diff(array_keys($job['ids']), array_keys($job['tasks']));
            if (!$remaining) return $this->complete($link, $job);
            $id = (int) reset($remaining);
            $wp = $client->getWorkPackage($id);
            if ((int) ($wp['id'] ?? 0) !== $id) throw new RuntimeException('Identidade inválida.');
            $job['current'] = [
                'id' => $id, 'subject' => mb_substr((string) ($wp['subject'] ?? ''), 0, 1000),
                'type' => mb_substr((string) ($wp['_links']['type']['title'] ?? ''), 0, 120),
                'status' => mb_substr((string) ($wp['_links']['status']['title'] ?? ''), 0, 120),
                'relationship' => $job['ids'][$id], 'releases' => [],
            ];
            $job['phase'] = 'activities'; $job['page'] = 1; $job['seen'] = [];
        } elseif ($job['phase'] === 'activities') {
            $collection = $client->getPublicationPage('activities', $job['current']['id'], $job['page']);
            foreach ($this->elements($collection) as $activity) {
                $activityWp = self::wpId((string) ($activity['_links']['workPackage']['href'] ?? ''));
                if ($activityWp !== $job['current']['id']) throw new RuntimeException('Atividade inconsistente.');
                foreach (self::parseComment((string) ($activity['comment']['raw'] ?? $activity['comment']['html'] ?? '')) as $release) {
                    $key = (int) $activity['id'] . ':' . $release['environment'] . ':' . $release['version'];
                    $job['current']['releases'][$key] = $release + [
                        'activity_id' => (int) $activity['id'],
                        'created_at' => (string) ($activity['createdAt'] ?? ''),
                    ];
                }
            }
            if (count($job['current']['releases']) > 1000) throw new RuntimeException('Limite de publicações excedido.');
            if ($this->finished($collection, $job)) {
                $job['current']['releases'] = array_values($job['current']['releases']);
                $job['tasks'][$job['current']['id']] = $job['current'];
                unset($job['current']);
                $job['phase'] = 'task'; $job['seen'] = []; $job['page'] = 1;
            }
        }
        $_SESSION[self::SESSION_KEY] = $job;
        return $this->progress($job);
    }

    private function complete(array $link, array $job): array
    {
        global $DB;
        $report = ['checked_at' => date('c'), 'tasks' => array_values($job['tasks'])];
        $DB->beginTransaction();
        try {
            // Conditional update prevents a stale browser job replacing a newer report.
            $where = ['id' => (int) $link['id'], 'tickets_id' => $job['ticket'], 'openproject_work_package_id' => $job['wp'],
                'release_details_json' => $job['previous'] === '' ? null : $job['previous']];
            $ok = $DB->update('glpi_plugin_demandas_links', ['release_details_json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], $where);
            if (!$ok || $DB->affectedRows() !== 1) throw new RuntimeException('O vínculo mudou.');
            $ok = $DB->insert('glpi_plugin_demandas_events', [
                'tickets_id' => $job['ticket'], 'openproject_work_package_id' => $job['wp'],
                'event_type' => 'publications_consulted', 'source' => 'publications', 'is_success' => 1,
                'new_status' => $link['openproject_status'],
                'message' => 'Consulta de TASKs e publicações concluída. Não altera fases públicas nem Work Packages.',
                'details_json' => json_encode(['sge_publications' => $report] + IntegrationActor::snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            if (!$ok) throw new RuntimeException('Falha ao registrar consulta.');
            $DB->commit();
        } catch (\Throwable $e) { $DB->rollBack(); throw $e; }
        $job['phase'] = 'done'; $job['report'] = $report;
        $_SESSION[self::SESSION_KEY] = $job;
        return $this->progress($job);
    }

    private function progress(array $job): array
    {
        $done = $job['phase'] === 'done';
        return ['job' => $job['id'], 'done' => $done, 'processed' => count($job['tasks']),
            'total' => $job['phase'] === 'relations' ? null : count($job['ids']),
            'report' => $done ? $job['report'] : null];
    }

    private function identityHash(): string
    {
        return hash('sha256', Config::personalToken() . "\0" . Config::get('openproject_internal_url', ''));
    }

    private static function wpId(string $href): int
    {
        return preg_match('~^/api/v3/work_packages/([1-9][0-9]*)$~', $href, $m) ? (int) $m[1] : 0;
    }

    private function elements(array $collection): array
    {
        $elements = $collection['_embedded']['elements'] ?? null;
        if (!is_array($elements)) throw new RuntimeException('Coleção incompleta.');
        return $elements;
    }

    private function finished(array $collection, array &$job): bool
    {
        $elements = $this->elements($collection);
        $before = count($job['seen']);
        foreach ($elements as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) throw new RuntimeException('Elemento inválido.');
            $job['seen'][(int) $row['id']] = true;
        }
        $total = $collection['total'] ?? null;
        if ($total !== null && (!is_numeric($total) || (int) $total < count($job['seen']))) throw new RuntimeException('Total inconsistente.');
        if ($elements !== [] && count($job['seen']) === $before) throw new RuntimeException('Paginação repetida.');
        if (is_numeric($total) && count($job['seen']) === (int) $total) return true;
        if ($elements === []) {
            if (is_numeric($total) && count($job['seen']) < (int) $total) throw new RuntimeException('Consulta incompleta.');
            return true;
        }
        if (count($job['seen']) === $before) throw new RuntimeException('Paginação repetida.');
        if ($total === null && empty($collection['_links']['nextByOffset']) && count($elements) < (int) ($collection['pageSize'] ?? 25)) return true;
        if (++$job['page'] > 1000) throw new RuntimeException('Limite de páginas excedido.');
        return false;
    }
}
