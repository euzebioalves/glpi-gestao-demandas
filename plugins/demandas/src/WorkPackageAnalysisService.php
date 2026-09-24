<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use RuntimeException;
use Ticket;

/**
 * Builds a user-controlled, local context package for an external AI chat.
 * It never calls an AI provider and never persists extracted ticket content.
 */
final class WorkPackageAnalysisService
{
    private const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
    private const MAX_ATTACHMENT_TEXT = 30000;
    private const MAX_PROMPT_TEXT = 180000;

    /** @var array<string, string> */
    public const SECTIONS = [
        'wp' => 'Work Package',
        'wp_status' => 'Status da WP',
        'wp_responsible' => 'Responsável da WP',
        'wp_customer' => 'Cliente da WP',
        'ticket_title' => 'Título do chamado',
        'ticket_number' => 'Número do chamado',
        'ticket_status' => 'Status do chamado',
        'links' => 'Links',
        'ticket_summary' => 'Resumo do chamado',
        'ticket_description' => 'Descrição completa do chamado',
        'ticket_followups' => 'Acompanhamentos públicos',
        'attachments' => 'Conteúdo extraído dos anexos selecionados',
    ];

    private object $db;

    public function __construct()
    {
        $this->db = DBConnection::getReadConnection();
    }

    /** Returns only data that the signed-in user can read in GLPI. */
    public function context(int $userId, int $workPackageId, int $ticketId = 0): array
    {
        Profile::checkRight(Profile::PREPARE_AI_CONTEXT);
        if ($userId <= 0 || $workPackageId <= 0) {
            throw new RuntimeException('Work Package inválida para a preparação da análise.');
        }

        $monitor = null;
        foreach ((new WorkPackageMonitoringService())->userRows($userId) as $row) {
            if ((int) ($row['openproject_work_package_id'] ?? 0) === $workPackageId) {
                $monitor = $row;
                break;
            }
        }
        if ($monitor === null) {
            throw new RuntimeException('A Work Package não está disponível na sua última consulta. Atualize a listagem e tente novamente.');
        }

        $ticketIds = array_values(array_unique(array_filter(array_map('intval', (array) ($monitor['ticket_ids'] ?? [])))));
        if ($ticketIds === []) {
            throw new RuntimeException('Não foi identificado um chamado GLPI vinculado a esta Work Package.');
        }
        if ($ticketId <= 0) {
            $ticketId = $ticketIds[0];
        }
        if (!in_array($ticketId, $ticketIds, true)) {
            throw new RuntimeException('O chamado selecionado não está vinculado a esta Work Package.');
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId) || !$ticket->can($ticketId, READ)) {
            throw new RuntimeException('Você não possui permissão para preparar conteúdo deste chamado.');
        }

        $followups = $this->followups($ticketId);
        return [
            'monitor' => $monitor,
            'ticket' => $ticket,
            'ticket_ids' => $ticketIds,
            'followups' => $followups,
            'attachments' => $this->attachments($ticketId, array_column($followups, 'id')),
        ];
    }

    /** @param array<int, string> $sections @param array<int, int|string> $attachmentIds */
    public function prepare(int $userId, int $workPackageId, int $ticketId, array $sections, array $attachmentIds): array
    {
        $context = $this->context($userId, $workPackageId, $ticketId);
        $selected = array_fill_keys(array_intersect(array_map('strval', $sections), array_keys(self::SECTIONS)), true);
        if ($selected === []) {
            throw new RuntimeException('Selecione ao menos uma informação para preparar a análise.');
        }

        $details = (array) ($context['monitor']['details'] ?? []);
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        $ticketContent = $this->plainText((string) ($ticket->fields['content'] ?? ''));
        $attachmentText = ['text' => '', 'results' => []];
        $parts = [
            '# Contexto para análise de demanda',
            'Use exclusivamente as informações abaixo. Aponte riscos, pendências, inconsistências entre o chamado e a Work Package e próximos passos. Não invente dados ausentes.',
        ];

        $workPackageId = (int) ($details['id'] ?? $workPackageId);
        if (isset($selected['wp'])) {
            $this->add($parts, 'Work Package', 'ID: #' . $workPackageId . "\nTítulo: " . (string) ($details['subject'] ?? '—') . "\nTipo: " . (string) ($details['type'] ?? '—'));
        }
        if (isset($selected['wp_status'])) {
            $this->add($parts, 'Status da WP', (string) ($details['status'] ?? '—'));
        }
        if (isset($selected['wp_responsible'])) {
            $this->add($parts, 'Responsável da WP', (string) ($details['responsible'] ?? '—'));
        }
        if (isset($selected['wp_customer'])) {
            $this->add($parts, 'Cliente da WP', (string) ($details['customer'] ?? '—'));
        }
        if (isset($selected['ticket_title'])) {
            $this->add($parts, 'Título do chamado', (string) ($ticket->fields['name'] ?? '—'));
        }
        if (isset($selected['ticket_number'])) {
            $this->add($parts, 'Número do chamado', '#' . $ticket->getID());
        }
        if (isset($selected['ticket_status'])) {
            $this->add($parts, 'Status do chamado', Ticket::getStatus((int) ($ticket->fields['status'] ?? 0)));
        }
        if (isset($selected['links'])) {
            $this->add($parts, 'Links', 'Work Package: ' . (string) ($details['openproject_url'] ?? '—') . "\nChamado: " . $this->ticketUrl($ticket->getID()));
        }
        if (isset($selected['ticket_summary'])) {
            $this->add($parts, 'Resumo do chamado', $this->limit($ticketContent, 3000));
        }
        if (isset($selected['ticket_description'])) {
            $this->add($parts, 'Descrição completa do chamado', $ticketContent);
        }
        if (isset($selected['ticket_followups'])) {
            $this->add($parts, 'Acompanhamentos públicos', $this->formatFollowups($context['followups']));
        }
        if (isset($selected['attachments'])) {
            $selectedIds = array_fill_keys(array_filter(array_map('intval', $attachmentIds)), true);
            $attachmentText = $this->extractAttachments($context['attachments'], $selectedIds);
            $this->add($parts, 'Conteúdo extraído dos anexos', $attachmentText['text']);
        }

        $prompt = $this->limit(implode("\n\n", $parts), self::MAX_PROMPT_TEXT);
        return [
            'prompt' => $prompt,
            'attachments' => $attachmentText['results'] ?? [],
            'ticket_id' => $ticket->getID(),
            'work_package_id' => $workPackageId,
        ];
    }

    private function add(array &$parts, string $title, string $text): void
    {
        $text = trim($text);
        if ($text !== '') {
            $parts[] = '## ' . $title . "\n" . $text;
        }
    }

    private function followups(int $ticketId): array
    {
        $followups = [];
        foreach ($this->db->request([
            'SELECT' => ['id', 'content', 'date_creation'],
            'FROM' => 'glpi_itilfollowups',
            'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $ticketId, 'is_private' => 0],
            'ORDER' => 'date_creation ASC, id ASC',
        ]) as $row) {
            $followups[] = [
                'id' => (int) ($row['id'] ?? 0),
                'content' => $this->plainText((string) ($row['content'] ?? '')),
                'date_creation' => (string) ($row['date_creation'] ?? ''),
            ];
        }
        return $followups;
    }

    private function formatFollowups(array $followups): string
    {
        if ($followups === []) {
            return 'Nenhum acompanhamento público foi encontrado.';
        }
        $lines = [];
        foreach ($followups as $followup) {
            $lines[] = '- ' . (string) ($followup['date_creation'] ?? '') . ': ' . (string) ($followup['content'] ?? '');
        }
        return implode("\n", $lines);
    }

    private function attachments(int $ticketId, array $followupIds): array
    {
        $relations = [[Ticket::class, $ticketId]];
        foreach (array_filter(array_map('intval', $followupIds)) as $followupId) {
            $relations[] = ['ITILFollowup', $followupId];
        }
        $attachments = [];
        foreach ($relations as [$itemtype, $itemId]) {
            foreach ($this->db->request([
                'SELECT' => ['documents_id'],
                'FROM' => 'glpi_documents_items',
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemId],
            ]) as $relation) {
                $documentId = (int) ($relation['documents_id'] ?? 0);
                if ($documentId <= 0 || isset($attachments[$documentId])) {
                    continue;
                }
                $document = new \Document();
                if (!$document->getFromDB($documentId) || !$document->can($documentId, READ)) {
                    continue;
                }
                $path = $this->documentPath((string) ($document->fields['filepath'] ?? ''));
                if ($path === null) {
                    continue;
                }
                $attachments[$documentId] = [
                    'id' => $documentId,
                    'name' => (string) ($document->fields['filename'] ?? ('Documento #' . $documentId)),
                    'mime' => (string) ($document->fields['mime'] ?? ''),
                    'size' => (int) (@filesize($path) ?: 0),
                    'path' => $path,
                ];
            }
        }
        return array_values($attachments);
    }

    private function extractAttachments(array $attachments, array $selectedIds): array
    {
        if ($selectedIds === []) {
            return ['text' => 'Nenhum anexo foi selecionado.', 'results' => []];
        }
        $sections = [];
        $results = [];
        foreach ($attachments as $attachment) {
            $id = (int) ($attachment['id'] ?? 0);
            if (!isset($selectedIds[$id])) {
                continue;
            }
            $name = (string) ($attachment['name'] ?? ('Documento #' . $id));
            $text = $this->extractAttachment($attachment);
            $results[] = ['name' => $name, 'extracted' => $text !== null];
            $sections[] = '### ' . $name . "\n" . ($text ?? 'Não foi possível extrair texto deste anexo localmente.');
        }
        return ['text' => $sections === [] ? 'Nenhum anexo válido foi selecionado.' : implode("\n\n", $sections), 'results' => $results];
    }

    private function extractAttachment(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');
        $size = (int) ($attachment['size'] ?? 0);
        if ($path === '' || !is_file($path) || $size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            return null;
        }
        $extension = mb_strtolower(pathinfo((string) ($attachment['name'] ?? ''), PATHINFO_EXTENSION));
        $text = match ($extension) {
            'txt', 'csv', 'json', 'xml' => file_get_contents($path) ?: null,
            'pdf' => $this->runTool(['timeout', '25s', 'pdftotext', '-layout', $path, '-']),
            'png', 'jpg', 'jpeg', 'webp' => $this->runTool(['timeout', '35s', 'tesseract', $path, 'stdout', '-l', 'por+eng']),
            'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods' => $this->extractOffice($path),
            default => null,
        };
        return $text === null ? null : $this->limit($this->plainText($text), self::MAX_ATTACHMENT_TEXT);
    }

    private function extractOffice(string $path): ?string
    {
        $directory = sys_get_temp_dir() . '/demandas-analysis-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }
        try {
            $this->runTool([
                'timeout', '35s', 'libreoffice', '--headless', '--nologo', '--nolockcheck',
                '-env:UserInstallation=file://' . $directory . '/profile', '--convert-to', 'txt:Text', '--outdir', $directory, $path,
            ]);
            $files = glob($directory . '/*.txt') ?: [];
            return $files === [] ? null : (file_get_contents($files[0]) ?: null);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function runTool(array $command): ?string
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0 && is_string($output) ? $output : null;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    private function documentPath(string $filepath): ?string
    {
        $root = realpath(GLPI_DOC_DIR);
        $path = $root === false ? false : realpath($root . '/' . ltrim(str_replace('\\', '/', $filepath), '/'));
        return is_string($path) && str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : null;
    }

    private function plainText(string $value): string
    {
        $value = preg_replace('~<(?:br|/p|/div|/li)\\b[^>]*>~i', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \\t]+/', ' ', preg_replace('/\\R{3,}/u', "\n\n", $value) ?? $value) ?? $value);
    }

    private function limit(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . "\n[Conteúdo truncado por limite local de segurança.]";
    }

    private function ticketUrl(int $ticketId): string
    {
        return rtrim((string) Config::get('glpi_external_url', 'http://localhost:8180'), '/') . '/front/ticket.form.php?id=' . $ticketId;
    }
}
