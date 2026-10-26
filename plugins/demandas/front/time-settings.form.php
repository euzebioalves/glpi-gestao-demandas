<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\AccessPolicy;use GlpiPlugin\Demandas\Profile as DemandasProfile;use GlpiPlugin\Demandas\TimeManagementService;
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Glpi\Exception\Http\BadRequestHttpException();
$current=(int)Session::getLoginUserID();$target=(int)($_POST['users_id']??$current);
GlpiPlugin\Demandas\TimeAccess::checkSettings($target);
try{[$opHref,$opName]=array_pad(explode('|',(string)($_POST['openproject_user']??''),2),2,'');$_POST['openproject_user_href']=$opHref;$_POST['openproject_user_name']=$opName;(new TimeManagementService())->saveSettings($target,$_POST);Session::addMessageAfterRedirect('Jornada atualizada.',true,INFO);}catch(Throwable$e){Session::addMessageAfterRedirect($e->getMessage(),true,ERROR);}Html::redirect('/plugins/demandas/front/timeclock.php?users_id='.$target);
