<?php

declare(strict_types=1);

use GlpiPlugin\Demandas\Profile as DemandasProfile;

Session::checkRight('profile', UPDATE);

$profileId = (int) ($_POST['profiles_id'] ?? 0);
$profile = new Profile();
if ($profileId <= 0 || !$profile->getFromDB($profileId)) {
    throw new Glpi\Exception\Http\NotFoundHttpException();
}

$selected = array_fill_keys(array_map('strval', (array) ($_POST['rights'] ?? [])), true);
foreach (DemandasProfile::definitions() as $right => $label) {
    DemandasProfile::setRight($profileId, $right, isset($selected[$right]));
}

Session::addMessageAfterRedirect('Permissões do plugin atualizadas.', true, INFO);
Html::redirect('/front/profile.form.php?id=' . $profileId . '&forcetab=GlpiPlugin\\Demandas\\Profile$1');
