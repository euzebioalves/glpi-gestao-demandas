<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\AccessPolicy;use GlpiPlugin\Demandas\Profile as DemandasProfile;use GlpiPlugin\Demandas\TimeManagementService;
Session::checkLoginUser();$current=(int)Session::getLoginUserID();$target=(int)($_POST['users_id']??$current);if($target!==$current)AccessPolicy::check(DemandasProfile::MANAGE_ATTENDANCE);else AccessPolicy::check(DemandasProfile::VIEW_OWN_ATTENDANCE);
try{[$opHref,$opName]=array_pad(explode('|',(string)($_POST['openproject_user']??''),2),2,'');$_POST['openproject_user_href']=$opHref;$_POST['openproject_user_name']=$opName;(new TimeManagementService())->saveSettings($target,$_POST);Session::addMessageAfterRedirect('Jornada atualizada.',true,INFO);}catch(Throwable$e){Session::addMessageAfterRedirect($e->getMessage(),true,ERROR);}Html::redirect('/plugins/demandas/front/timeclock.php?users_id='.$target);
