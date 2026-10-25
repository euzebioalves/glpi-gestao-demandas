<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class BacklogDetails
{
    private static function workPackage(int $id, OpenProjectClient $client): array
    {
        BacklogHub::checkAccess();
        if ($id <= 0) throw new \InvalidArgumentException('WP inválida.');
        $wp = $client->getWorkPackage($id);
        if ((int) ($wp['id'] ?? 0) !== $id) throw new \RuntimeException('WP indisponível.');
        $me = (int) ($client->getCurrentUser()['id'] ?? 0);
        if ($me <= 0) throw new \RuntimeException('Identidade pessoal inválida.');
        $owner = (string) ($wp['_links']['responsible']['href'] ?? $wp['_links']['accountable']['href'] ?? '');
        if (!preg_match('~/users/' . $me . '$~', $owner)) Profile::checkRight(Profile::VIEW_COLLEAGUE_BACKLOG);
        return $wp;
    }

    public static function load(int $id): array
    {
        $client = OpenProjectClient::forCurrentUser();
        $wp = self::workPackage($id, $client);
        $raw = (string) ($wp['description']['raw'] ?? '');
        $html = (string) ($wp['description']['html'] ?? '');
        if (strlen($raw) > 500000 || strlen($html) > 1000000) throw new \RuntimeException('Descrição muito extensa.');
        if ($html === '') {
            $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false, 'max_nesting_level' => 50]);
            $html = (string) $converter->convert($raw);
        }
        $html = \Glpi\RichText\RichText::getSafeHtml($html);
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $attachments = [];
        if ($dom->getElementsByTagName('img')->length) {
            try {
                foreach ($client->workPackageAttachments($id) as $attachment) $attachments[(int) $attachment['id']] = true;
            } catch (\RuntimeException) {
                // Keep readable text when the API cannot provide attachment metadata.
            }
        }
        $base = rtrim((string) Config::get('openproject_external_url', ''), '/');
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            $src = $img->getAttribute('src');
            $path = str_starts_with($src, $base . '/') ? substr($src, strlen($base)) : $src;
            if (preg_match('~^/(?:api/v3/attachments|attachments)/(\d+)/(?:content|[^/?#]+)(?:\?[^#]*)?$~', $path, $match)
                && isset($attachments[(int) $match[1]])) {
                $img->setAttribute('src', '/plugins/demandas/front/backlog-data.php?action=image&wp=' . $id . '&attachment=' . (int) $match[1]);
                $img->removeAttribute('srcset');
                $img->setAttribute('loading', 'lazy');
            } else {
                $img->parentNode?->replaceChild($dom->createTextNode('[Imagem disponível no OpenProject]'), $img);
            }
        }
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            if (str_starts_with($href, '/') && !str_starts_with($href, '//')) $href = $base . $href;
            if (!preg_match('~^https?://~i', $href)) $link->removeAttribute('href');
            else $link->setAttribute('href', $href);
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer');
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $html = '';
        foreach ($body?->childNodes ?? [] as $node) $html .= $dom->saveHTML($node);
        return ['html' => \Glpi\RichText\RichText::getSafeHtml($html),
            'ticket_ids' => (new BacklogService())->tickets([$id])[$id] ?? []];
    }

    public static function image(int $wpId, int $attachmentId): void
    {
        $client = OpenProjectClient::forCurrentUser();
        self::workPackage($wpId, $client);
        $ids = array_map('intval', array_column($client->workPackageAttachments($wpId), 'id'));
        if ($attachmentId <= 0 || !in_array($attachmentId, $ids, true)) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        $bytes = $client->documentationImage($attachmentId);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) throw new \RuntimeException('Imagem indisponível.');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    }
}
