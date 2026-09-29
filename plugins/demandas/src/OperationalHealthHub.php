<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use CommonGLPI;
use Session;

/** Entry point for the locally-audited integration health queue. */
final class OperationalHealthHub extends CommonGLPI
{
    public static $rightname = Profile::VIEW_OPERATIONAL_HEALTH;

    public static function getTypeName($nb = 0): string
    {
        return 'Pendências de Integração';
    }

    public static function getIcon(): string
    {
        return 'ti ti-alert-triangle';
    }

    public static function canView(): bool
    {
        return (int) Session::getLoginUserID() > 0
            && Profile::has(Profile::VIEW_TECHNICAL)
            && Profile::has(Profile::VIEW_OPERATIONAL_HEALTH);
    }

    public static function getMenuContent(): array|false
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page' => '/plugins/demandas/front/operational-health.php',
            'icon' => self::getIcon(),
            'links' => ['search' => '/plugins/demandas/front/operational-health.php'],
        ];
    }
}
