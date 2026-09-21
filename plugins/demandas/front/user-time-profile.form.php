<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\AccessPolicy;
use GlpiPlugin\Demandas\Profile as DemandasProfile;
use GlpiPlugin\Demandas\TimeManagementService;

Session::checkLoginUser();
$db = DBConnection::getReadConnection();
$current = (int) Session::getLoginUserID();
$userId = (int) ($_POST['users_id'] ?? 0);
if ($userId <= 0) throw new Glpi\Exception\Http\NotFoundHttpException();

try {
    if (isset($_POST['save_settings'])) {
        if ($userId !== $current) AccessPolicy::check(DemandasProfile::MANAGE_ATTENDANCE);
        $input = $_POST;
        $input['openproject_user_name'] = '';
        (new TimeManagementService())->saveSettings($userId, $input);
        Session::addMessageAfterRedirect('Configuração individual atualizada.', true, INFO);
    } elseif (isset($_POST['save_rights'])) {
        AccessPolicy::check(DemandasProfile::MANAGE_TIME_ACCESS);
        foreach ((array) ($_POST['rights'] ?? []) as $right => $decision) {
            if (!array_key_exists((string)$right, DemandasProfile::definitions())) continue;
            $found = null;
            foreach ($db->request(['FROM'=>'glpi_plugin_demandas_user_rights','WHERE'=>['users_id'=>$userId,'right_name'=>(string)$right],'LIMIT'=>1]) as $row) $found = $row;
            if ((string)$decision === '-1') {
                if ($found !== null) $db->delete('glpi_plugin_demandas_user_rights', ['id'=>(int)$found['id']]);
                continue;
            }
            $data = ['users_id'=>$userId,'right_name'=>(string)$right,'decision'=>(int)$decision,'date_mod'=>date('Y-m-d H:i:s')];
            if ($found !== null) $db->update('glpi_plugin_demandas_user_rights',$data,['id'=>(int)$found['id']]);
            else $db->insert('glpi_plugin_demandas_user_rights',$data);
        }
        Session::addMessageAfterRedirect('Permissões individuais atualizadas.', true, INFO);
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect($exception->getMessage(), true, ERROR);
}
Html::redirect('/front/user.form.php?id=' . $userId . '&forcetab=GlpiPlugin\\Demandas\\UserTimeProfile$1');
