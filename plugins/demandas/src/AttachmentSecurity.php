<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class AttachmentSecurity
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    public static function upload(array $file, array $allowed): array
    {
        $path = (string) ($file['tmp_name'] ?? '');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            throw new \DomainException('Não foi possível receber o anexo.');
        }
        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new \DomainException('Nome de anexo inválido.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) throw new \DomainException('Formato de anexo não permitido.');
        return ['name' => $name, 'extension' => $extension] + self::inspect($path, $extension);
    }

    /** Inspect bytes, never browser MIME or declared length. No archive is extracted. */
    public static function inspect(string $path, string $extension): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if (!$size || $size > self::MAX_BYTES) throw new \DomainException('Anexo vazio ou maior que 15 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = file_get_contents($path, false, null, 0, 1024);
        $valid = false;
        $images = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        if (isset($images[$extension])) {
            $dimensions = @getimagesize($path);
            $valid = $mime === $images[$extension] && is_array($dimensions)
                && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= 40000000;
        } elseif ($extension === 'pdf') {
            $valid = $mime === 'application/pdf' && str_starts_with((string) $head, '%PDF-');
        } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $valid = str_starts_with((string) $head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        } elseif (in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'zip'], true)) {
            $valid = self::archive($path, $extension);
        } elseif (in_array($extension, ['txt', 'csv', 'log', 'json', 'xml'], true)) {
            $valid = is_string($mime) && (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true))
                && !str_contains((string) $head, "\0");
        }
        if (!$valid) throw new \DomainException('O conteúdo do anexo não corresponde ao formato informado ou excede os limites de segurança.');
        return ['mime' => $mime ?: 'application/octet-stream', 'size' => $size];
    }

    private static function archive(string $path, string $extension): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) return false;
        try {
            if ($zip->numFiles > 2000) return false;
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!$entry) return false;
                $total += $entry['size'];
                if ($total > 100 * 1024 * 1024 || ($entry['size'] > 1024 * 1024 && $entry['size'] > max(1, $entry['comp_size']) * 200)) return false;
            }
            $required = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml', 'odt' => 'content.xml', 'ods' => 'content.xml'];
            if (isset($required[$extension]) && $zip->locateName($required[$extension]) === false) return false;
            if (in_array($extension, ['odt', 'ods'], true)) {
                $expected = 'application/vnd.oasis.opendocument.' . ($extension === 'odt' ? 'text' : 'spreadsheet');
                if ($zip->getFromName('mimetype', 100) !== $expected) return false;
            }
            return true;
        } finally { $zip->close(); }
    }
}
