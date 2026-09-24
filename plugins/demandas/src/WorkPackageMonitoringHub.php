<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;
use Session;

final class WorkPackageMonitoringHub extends CommonGLPI
{
    public static $rightname = Profile::VIEW_PUBLIC;

    public static function getTypeName($nb = 0): string
    {
        return 'Monitoramento de Work Packages';
    }

    public static function getIcon(): string
    {
        return 'ti ti-list-search';
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0;
    }

    public static function getMenuContent(): array|false
    {
        if (!self::canView()) {
            return false;
        }
        $options = [
            'mine' => ['title' => 'Minhas Work Packages', 'page' => '/plugins/demandas/front/work-package-monitor.php', 'icon' => self::getIcon()],
            'notifications' => ['title' => 'Alertas de Work Packages', 'page' => '/plugins/demandas/front/work-package-notifications.php', 'icon' => 'ti ti-bell'],
        ];
        if (Profile::has(Profile::VIEW_DASHBOARD)) {
            $options['consolidated'] = ['title' => 'Consolidado de Work Packages', 'page' => '/plugins/demandas/front/work-package-consolidated.php', 'icon' => 'ti ti-layout-dashboard'];
        }
        return [
            'title' => self::getTypeName(),
            'page' => '/plugins/demandas/front/work-package-monitor.php',
            'icon' => self::getIcon(),
            'links' => ['search' => '/plugins/demandas/front/work-package-monitor.php'],
            'options' => $options,
        ];
    }
}
