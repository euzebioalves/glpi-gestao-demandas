<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

final class WorkPackageTitle
{
    public const MAX_LENGTH = 255;

    public static function suggested(\Ticket $ticket): string
    {
        return sprintf('[GLPI #%d] %s', $ticket->getID(), $ticket->fields['name']);
    }

    /** Null preserves callers from earlier versions; an explicitly empty title is invalid. */
    public static function resolve(\Ticket $ticket, mixed $value = null): string
    {
        $value ??= self::suggested($ticket);
        if (!is_string($value)) throw new \DomainException('Informe um título válido para a Work Package.');
        $value = trim($value);
        if ($value === '' || preg_match('/^\s*$/u', $value)) throw new \DomainException('Informe o título da Work Package.');
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new \DomainException('O título deve conter texto em uma única linha.');
        }
        if (mb_strlen($value) > self::MAX_LENGTH) throw new \DomainException('O título da Work Package deve ter no máximo 255 caracteres.');
        return $value;
    }
}
