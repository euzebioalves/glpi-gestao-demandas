<?php
declare(strict_types=1);
namespace GlpiPlugin\Demandas;

/** Personal default is a navigation preference, never an authorization. */
final class DashboardView
{
    public const TABLE = 'glpi_plugin_demandas_dashboard_preferences';

    public static function canBugs(): bool
    {
        return Profile::has(Profile::VIEW_DASHBOARD) && Profile::has(Profile::VIEW_TECHNICAL)
            && Profile::has(Profile::VIEW_BUGS_DASHBOARD);
    }

    public static function canReview(): bool
    {
        return self::canBugs() && Profile::has(Profile::VIEW_DELIVERY_REVIEW);
    }

    public static function resolve(array $input): string
    {
        Profile::checkRight(Profile::VIEW_DASHBOARD);
        if (isset($input['view'])) {
            $view = $input['view'];
            if (!in_array($view, ['general', 'bugs'], true)) {
                throw new \Glpi\Exception\Http\BadRequestHttpException('Visão inválida.');
            }
            if ($view === 'bugs' && !self::canBugs()) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            return $view;
        }
        if (!Profile::has(Profile::CONFIGURE_DASHBOARD)) return 'general';
        global $DB;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['users_id' => (int) \Session::getLoginUserID()]]) as $row) {
            return $row['default_view'] === 'bugs' && self::canBugs() ? 'bugs' : 'general';
        }
        return 'general';
    }

    public static function save(mixed $view): void
    {
        Profile::checkRight(Profile::CONFIGURE_DASHBOARD);
        $view = self::resolve(['view' => $view]);
        $user = (int) \Session::getLoginUserID();
        if ($user <= 0) throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        global $DB;
        if (!$DB->updateOrInsert(self::TABLE, ['default_view' => $view, 'date_mod' => date('Y-m-d H:i:s')], ['users_id' => $user])) {
            throw new \RuntimeException('Não foi possível salvar sua visão padrão.');
        }
    }
}
