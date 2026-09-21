<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;

final class TimeEntryHub extends CommonGLPI
{
    public static $rightname = Profile::LOG_OWN_TIME;
    public static function getTypeName($nb = 0): string { return 'Entradas de Tempo'; }
    public static function getIcon(): string { return 'ti ti-clock-share'; }
    public static function canView(): bool { return AccessPolicy::has(Profile::VIEW_TIME_PORTAL) && AccessPolicy::has(Profile::LOG_OWN_TIME); }
    public static function getMenuContent(): array|false
    {
        if (!self::canView()) return false;
        return ['title'=>self::getTypeName(),'page'=>'/plugins/demandas/front/time-entry.php','icon'=>self::getIcon(),'links'=>['search'=>'/plugins/demandas/front/time-entry.php','add'=>'/plugins/demandas/front/time-entry.php?new=1']];
    }
}
