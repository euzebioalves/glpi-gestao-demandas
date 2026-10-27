<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DomainException;
use Session;
use Ticket;
use Glpi\RichText\RichText;

/** Editor sessions contain metadata only; documents and files remain in OpenProject. */
final class WorkPackageDocumentation
{
    public const RELATIONS = [
        'relates' => 'Relacionada a', 'duplicates' => 'Duplica', 'duplicated' => 'Duplicada por',
        'blocks' => 'Bloqueia', 'blocked' => 'Bloqueada por', 'precedes' => 'Precede',
        'follows' => 'Segue', 'includes' => 'Inclui', 'partof' => 'Parte de',
        'requires' => 'Requer', 'required' => 'Requerida por',
    ];

    public static function access(int $ticketId, int $wpId = 0): Ticket
    {
        Session::checkLoginUser();
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        Profile::checkRight(Profile::CREATE_WORK_PACKAGE);
        $ticket = new Ticket();
        if ($ticketId <= 0 || !$ticket->getFromDB($ticketId)
            || !in_array((int) $ticket->fields['entities_id'], array_map('intval', Session::getActiveEntities()), true)
            || !empty($ticket->fields['is_deleted']) || !$ticket->can($ticketId, READ) || !$ticket->can($ticketId, UPDATE)
            || ($wpId > 0 && TicketDemand::findByWorkPackage($wpId, $ticketId) === null)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
        return $ticket;
    }

    public static function start(int $ticket, int $wp = 0): string
    {
        self::access($ticket, $wp);
        foreach ($_SESSION['demandas_documentation'] ?? [] as $key => $value) {
            if (($value['expires'] ?? 0) < time()) unset($_SESSION['demandas_documentation'][$key]);
        }
        if (count($_SESSION['demandas_documentation'] ?? []) >= 20) {
            foreach ($_SESSION['demandas_documentation'] as $key => $value) {
                if (!empty($value['done'])) { unset($_SESSION['demandas_documentation'][$key]); break; }
            }
        }
        if (count($_SESSION['demandas_documentation'] ?? []) >= 20) throw new DomainException('Feche os editores anteriores e aguarde a expiração antes de abrir outros.');
        $id = bin2hex(random_bytes(24));
        $_SESSION['demandas_documentation'][$id] = [
            'ticket' => $ticket, 'wp' => $wp, 'expires' => time() + 7200,
            'identity' => self::identity(), 'uploads' => [], 'attachments' => [], 'created' => 0,
        ];
        return $id;
    }

    private static function identity(): string
    {
        return hash('sha256', Session::getLoginUserID() . ':' . Config::personalToken() . ':' . Config::get('openproject_internal_url'));
    }

    public static function &draft(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $id) || !isset($_SESSION['demandas_documentation'][$id])) {
            throw new DomainException('Sessão de edição inválida. Reabra a documentação pelo chamado.');
        }
        $draft = &$_SESSION['demandas_documentation'][$id];
        if (!is_array($draft) || ($draft['expires'] ?? 0) < time() || !hash_equals($draft['identity'] ?? '', self::identity())) {
            throw new DomainException('A sessão de edição expirou ou o token mudou. Copie seu texto e reabra o editor.');
        }
        self::access($draft['ticket'], $draft['wp']);
        return $draft;
    }

    public static function load(string $id): array
    {
        $draft = &self::draft($id);
        $client = OpenProjectClient::forCurrentUser();
        if (!$draft['wp']) return ['html' => '', 'raw' => '', 'attachments' => []];
        $wp = $client->getWorkPackage($draft['wp']);
        if ((int) ($wp['id'] ?? 0) !== $draft['wp']) throw new DomainException('WP não confirmada pelo OpenProject.');
        $draft['lock'] = (int) $wp['lockVersion'];
        $draft['attachments'] = [];
        foreach ($client->workPackageAttachments($draft['wp']) as $attachment) {
            $draft['attachments'][(int) $attachment['id']] = self::attachment($attachment);
        }
        $raw = (string) ($wp['description']['raw'] ?? '');
        return ['html' => self::html($raw, $id), 'raw' => $raw, 'attachments' => array_values($draft['attachments'])];
    }

    private static function attachment(array $attachment): array
    {
        $id = (int) ($attachment['id'] ?? 0);
        if ($id <= 0) throw new DomainException('O OpenProject não confirmou o arquivo enviado.');
        return ['id' => $id, 'name' => (string) ($attachment['fileName'] ?? ''), 'mime' => (string) ($attachment['contentType'] ?? ''),
            'url' => rtrim((string) Config::get('openproject_external_url'), '/') . '/api/v3/attachments/' . $id . '/content'];
    }

    public static function upload(string $id, array $file): array
    {
        $draft = &self::draft($id);
        if (!empty($draft['done']) || !empty($draft['creating'])) throw new DomainException('Este envio já foi encerrado. Reabra a documentação da WP.');
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'csv', 'log', 'json', 'xml', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods'];
        $validated = AttachmentSecurity::upload($file, $allowed);
        $name = $validated['name'];
        $mime = $validated['mime'];
        $fingerprint = hash_file('sha256', $file['tmp_name']) . ':' . $name;
        foreach ($draft['uploads'] as $uploaded) if ($uploaded['fingerprint'] === $fingerprint) return $uploaded;
        if (count($draft['uploads']) >= 20) throw new DomainException('Limite de 20 arquivos por edição.');
        $result = OpenProjectClient::forCurrentUser()->uploadDocumentationAttachment($file['tmp_name'], $name, $mime ?: 'application/octet-stream');
        $attachment = self::attachment($result) + ['fingerprint' => $fingerprint];
        $attachment['preview'] = self::imageUrl($id, $attachment['id']);
        $draft['uploads'][$attachment['id']] = $attachment;
        return $attachment;
    }

    public static function imageUrl(string $draft, int $attachment): string
    {
        return '/plugins/demandas/front/work-package-documentation.php?action=image&draft=' . rawurlencode($draft) . '&attachment=' . $attachment;
    }

    public static function html(string $markdown, string $draftId): string
    {
        if (strlen($markdown) > 500000) throw new DomainException('A documentação excede o limite de 500 KB do editor.');
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
        $html = RichText::getSafeHtml((string) $converter->convert($markdown));
        $draft = &self::draft($draftId);
        foreach ($draft['attachments'] + $draft['uploads'] as $attachment) {
            $path = '/api/v3/attachments/' . $attachment['id'] . '/content';
            $html = str_replace(['src="' . $attachment['url'] . '"', 'src="' . $path . '"'], 'src="' . self::imageUrl($draftId, $attachment['id']) . '"', $html);
        }
        return $html;
    }

    public static function markdown(string $html, string $draftId): string
    {
        $draft = &self::draft($draftId);
        if (strlen($html) > 1000000) throw new DomainException('A documentação excede o limite do editor.');
        $html = RichText::getSafeHtml($html);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $preserved = [];
        // Keep code literal (including backticks and HTML), tables and image dimensions.
        foreach (['pre', 'img', 'table'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                if (!$node->parentNode) continue;
                if ($tag === 'pre') {
                    $code = $node->textContent;
                    preg_match('/language-([a-zA-Z0-9_+-]+)/', $node->getAttribute('class') . ' ' . ($node->getElementsByTagName('code')->item(0)?->getAttribute('class') ?? ''), $language);
                    preg_match_all('/`+/', $code, $runs);
                    $fence = str_repeat('`', max(3, ...array_map(static fn($s) => strlen($s) + 1, $runs[0] ?: [''])));
                    $value = "\n\n" . $fence . ($language[1] ?? '') . "\n" . $code . "\n" . $fence . "\n\n";
                } elseif ($tag === 'img') {
                    $src = $node->getAttribute('src');
                    foreach ($draft['uploads'] + $draft['attachments'] as $attachment) {
                        if ($src === self::imageUrl($draftId, $attachment['id']) || html_entity_decode($src) === self::imageUrl($draftId, $attachment['id'])) $src = $attachment['url'];
                    }
                    if (!preg_match('~^https?://~i', $src)) throw new DomainException('Aguarde o envio de todas as imagens antes de salvar.');
                    $image = $dom->createElement('img');
                    $image->setAttribute('src', $src); $image->setAttribute('alt', $node->getAttribute('alt'));
                    $style = [];
                    foreach (['width', 'height'] as $dimension) {
                        $size = $node->getAttribute($dimension);
                        if (preg_match('/(?:^|;)\s*' . $dimension . '\s*:\s*([0-9.]+(?:px|%))/', $node->getAttribute('style'), $m)) $size = $m[1];
                        if (preg_match('/^[0-9]{1,5}(?:\.[0-9]+)?(?:px|%)?$/', $size)) $style[] = $dimension . ':' . (is_numeric($size) ? $size . 'px' : $size);
                    }
                    if ($style) $image->setAttribute('style', implode(';', $style));
                    $value = $dom->saveHTML($image);
                } else $value = "\n\n" . $dom->saveHTML($node) . "\n\n";
                $key = 'DEMANDASPRESERVE' . bin2hex(random_bytes(16));
                $preserved[$key] = $value;
                $node->parentNode->replaceChild($dom->createTextNode($key), $node);
            }
        }
        $converter = new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true, 'hard_break' => true]);
        $body = $dom->getElementsByTagName('body')->item(0);
        $fragment = '';
        foreach ($body?->childNodes ?? [] as $child) $fragment .= $dom->saveHTML($child);
        // The XML encoding hint belongs only to DOMDocument, never to the Markdown document.
        $markdown = $converter->convert($fragment);
        // Tables can contain image placeholders; resolve them after restoring tables.
        for ($i = 0; $i < 3; $i++) $markdown = strtr($markdown, $preserved);
        return trim($markdown);
    }

    public static function template(string $draftId, array $input): array
    {
        $draft = &self::draft($draftId);
        $ticket = self::access($draft['ticket']);
        $client = OpenProjectClient::forCurrentUser();
        foreach (ClassificationPolicy::filterTypes($ticket, $client->getTypesForProject((int) ($input['project_id'] ?? 0))) as $type) {
            if ((int) $type['id'] !== (int) ($input['type_id'] ?? 0)) continue;
            $overrides = [];
            $options = $client->getCreationOptions((int) $input['project_id'], (int) $input['type_id']);
            foreach ($options['customer']['options'] ?? [] as $customer) {
                if ($customer['href'] === ($input['wp_customer'] ?? '')) $overrides['cliente'] = $customer['label'];
            }
            $raw = WorkPackageTemplate::render($ticket, $type['name'], (string) ($input['additional_wp_reason'] ?? ''), $overrides);
            return ['raw' => $raw, 'html' => self::html($raw, $draftId)];
        }
        throw new DomainException('Selecione um projeto e um tipo permitidos para carregar o template.');
    }

    public static function save(string $draftId, array $input): array
    {
        $draft = &self::draft($draftId);
        if (!empty($draft['done'])) return $draft['done'];
        $ticket = self::access($draft['ticket'], $draft['wp']);
        $client = OpenProjectClient::forCurrentUser();
        $markdown = ($input['format'] ?? '') === 'markdown' ? (string) ($input['documentation'] ?? '') : self::markdown((string) ($input['documentation'] ?? ''), $draftId);
        if (strlen($markdown) > 500000) throw new DomainException('A documentação excede 500 KB.');
        $attachmentIds = array_map('intval', array_keys($draft['attachments'] + $draft['uploads']));
        $wpId = $draft['wp'];
        if ($wpId) {
            if (!isset($draft['lock'])) throw new DomainException('Carregue a documentação antes de salvar.');
            $wp = $client->getWorkPackage($wpId);
            if ((int) $wp['lockVersion'] !== $draft['lock']) throw new DomainException('A WP foi alterada no OpenProject. Copie seu texto, recarregue e compare antes de salvar.');
            $updated = $client->updateDocumentation($wpId, $draft['lock'], $markdown, $attachmentIds);
            $draft['lock'] = (int) ($updated['lockVersion'] ?? $draft['lock'] + 1);
        } else {
            $reason = trim((string) ($input['additional_wp_reason'] ?? ''));
            $links = TicketDemand::findAllByTicket($draft['ticket']);
            if (($links !== [] && $reason === '') || mb_strlen($reason) > 2000) throw new DomainException('Informe o motivo da nova WP (até 2.000 caracteres).');
            $target = (int) ($input['relation_target'] ?? 0);
            $relation = (string) ($input['relation_type'] ?? 'relates');
            if ($target > 0) {
                if (($input['confirm_relation'] ?? '') !== '1' || !isset(self::RELATIONS[$relation]) || TicketDemand::findByWorkPackage($target, $draft['ticket']) === null) throw new DomainException('Confirme o relacionamento com uma WP vinculada a este chamado e selecione o tipo.');
                $previous = $client->getWorkPackage($target);
                if ((int) ($previous['id'] ?? 0) !== $target) throw new DomainException('A WP anterior não pôde ser confirmada.');
            }
            if (!$draft['created']) {
                if (!empty($draft['creating'])) throw new DomainException('O resultado da criação anterior não foi confirmado. Confira no OpenProject e concilie a WP se ela existir; não repita a criação.');
                $selection = [];
                foreach (['assignee', 'responsible', 'priority', 'customer'] as $key) $selection[$key] = (string) ($input['wp_' . $key] ?? '');
                // Validate the template/type before the irreversible external operation.
                self::template($draftId, $input);
                $draft['creating'] = true;
                try {
                    $wp = $client->createWorkPackage($ticket, (int) ($input['project_id'] ?? 0), (int) ($input['type_id'] ?? 0), $selection, $reason, $markdown, $attachmentIds);
                } catch (\Throwable $exception) {
                    $cause = $exception->getPrevious();
                    $status = $cause instanceof \GuzzleHttp\Exception\RequestException ? $cause->getResponse()?->getStatusCode() : null;
                    if ($cause === null || in_array($status, [400, 401, 403, 404, 409, 422], true)) {
                        $draft['creating'] = false;
                        throw new DomainException('O OpenProject não aceitou a criação. Confira os campos obrigatórios, o token e as permissões antes de tentar novamente.');
                    }
                    throw new DomainException('O resultado da criação não foi confirmado. Confira no OpenProject antes de continuar; se a WP existir, concilie seu vínculo. Seu texto permanece no editor.');
                }
                $draft['created'] = (int) $wp['id'];
                $draft['metadata'] = $wp['_demandas'];
                $draft['relation_target'] = $target; $draft['relation_type'] = $relation;
            }
            $wpId = $draft['created'];
            $meta = $draft['metadata'];
            TicketDemand::saveLink($draft['ticket'], $wpId, $meta['project_id'], $meta['project_name'], $meta['project_identifier'], $meta['type_id'], $meta['type_name'], null, $meta['details']);
            if ($draft['relation_target'] > 0) {
                try { $client->relateWorkPackages($wpId, $draft['relation_target'], $draft['relation_type']); }
                catch (\Throwable) { throw new DomainException('A WP #' . $wpId . ' foi criada e vinculada, mas a relação não foi confirmada. Clique em salvar para tentar apenas a relação novamente; nenhuma nova WP será criada.'); }
            }
            try { (new SynchronizationService())->synchronizeByWorkPackage($wpId, 'creation'); }
            catch (\Throwable) {
                // Creation is already confirmed; never repeat it on a synchronization error.
                Session::addMessageAfterRedirect('WP #' . $wpId . ' criada. A sincronização de status ficou pendente; use Sincronizar agora no chamado.', true, WARNING);
            }
        }
        global $DB;
        $DB->insert('glpi_plugin_demandas_events', [
            'tickets_id' => $draft['ticket'], 'openproject_work_package_id' => $wpId,
            'event_type' => 'documentation_saved', 'source' => 'documentation', 'is_success' => 1,
            'message' => 'Documentação salva no OpenProject pelo editor do GLPI.',
            'details_json' => json_encode(IntegrationActor::snapshot() + ['id' => $wpId, 'relation_target' => $draft['relation_target'] ?? 0, 'relation_type' => $draft['relation_type'] ?? ''], JSON_THROW_ON_ERROR),
        ]);
        Session::addMessageAfterRedirect('Documentação salva no OpenProject, na WP #' . $wpId . '.', true, INFO);
        return $draft['done'] = ['wp' => $wpId, 'redirect' => LegacyReconciliationService::ticketReturnUrl($draft['ticket'])];
    }
}
