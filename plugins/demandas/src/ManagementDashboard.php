<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;

final class ManagementDashboard extends CommonGLPI
{
    public static $rightname = Profile::VIEW_DASHBOARD;

    public static function getTypeName($nb = 0): string
    {
        return Config::label('management_dashboard');
    }

    public static function getIcon(): string
    {
        return 'ti ti-chart-dots-3';
    }

    public static function canView(): bool
    {
        return Profile::has(Profile::VIEW_DASHBOARD);
    }

    public static function getMenuContent(): array|false
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page' => '/plugins/demandas/front/dashboard.php',
            'icon' => self::getIcon(),
            'links' => ['search' => '/plugins/demandas/front/dashboard.php'],
        ];
    }
}
