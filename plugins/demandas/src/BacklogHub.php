<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

final class BacklogHub extends \CommonGLPI
{
    public static $rightname = Profile::VIEW_BACKLOG;
    public static function getTypeName($nb = 0): string { return 'Backlog'; }
    public static function getIcon(): string { return 'ti ti-layout-kanban'; }
    public static function canView(): bool
    {
        return \Session::getLoginUserID() > 0 && Profile::has(Profile::VIEW_TECHNICAL) && Profile::has(Profile::VIEW_BACKLOG);
    }
    public static function checkAccess(): void
    {
        \Session::checkLoginUser();
        Profile::checkRight(Profile::VIEW_TECHNICAL);
        Profile::checkRight(Profile::VIEW_BACKLOG);
    }
    public static function getMenuContent(): array|false
    {
        if (!self::canView()) return false;
        return ['title' => 'Backlog', 'page' => '/plugins/demandas/front/backlog.php',
            'icon' => self::getIcon(), 'links' => ['search' => '/plugins/demandas/front/backlog.php']];
    }
}
