<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;

final class WorkforceHub extends CommonGLPI
{
    public static $rightname = Profile::VIEW_TIME_PORTAL;

    public static function getTypeName($nb = 0): string { return 'Horas e Ponto'; }
    public static function getIcon(): string { return 'ti ti-clock-hour-4'; }
    public static function canView(): bool { return AccessPolicy::has(Profile::VIEW_TIME_PORTAL); }

    public static function getMenuContent(): array|false
    {
        if (!self::canView()) return false;
        return [
            'title' => self::getTypeName(),
            'page' => '/plugins/demandas/front/timeclock.php',
            'icon' => self::getIcon(),
            'links' => ['search' => '/plugins/demandas/front/timeclock.php'],
            'options' => [
                'timeclock' => ['title' => 'Meu ponto', 'page' => '/plugins/demandas/front/timeclock.php', 'icon' => 'ti ti-calendar-time'],
                'admin' => ['title' => 'Administração do ponto', 'page' => '/plugins/demandas/front/timeclock-admin.php', 'icon' => 'ti ti-settings'],
            ],
        ];
    }
}
