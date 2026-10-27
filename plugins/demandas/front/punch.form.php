<?php
declare(strict_types=1);
use GlpiPlugin\Demandas\AccessPolicy;use GlpiPlugin\Demandas\Profile as DemandasProfile;use GlpiPlugin\Demandas\TimeManagementService;
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Glpi\Exception\Http\BadRequestHttpException();
$current=(int)Session::getLoginUserID();$target=(int)($_POST['users_id']??$current);
GlpiPlugin\Demandas\TimeAccess::checkAttendance($target, true);
try{$s=new TimeManagementService();$date=(string)($_POST['punch_date']??'');$files=[];$upload=$_FILES['absence_files']??null;if(is_array($upload)&&is_array($upload['name']??null))foreach($upload['name']as$i=>$name)$files[]=['name'=>$name,'type'=>$upload['type'][$i]??'','tmp_name'=>$upload['tmp_name'][$i]??'','error'=>$upload['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$upload['size'][$i]??0];$count=$s->saveDay($target,$date,(array)($_POST['records']??[]),(string)($_POST['absence_action']??'keep'),$_POST,$files);Session::addMessageAfterRedirect($count.' marcação(ões) salva(s).',true,INFO);}catch(Throwable$e){Session::addMessageAfterRedirect(\GlpiPlugin\Demandas\IntegrationSecurity::html($e),true,ERROR);}Html::redirect('/plugins/demandas/front/timeclock.php?users_id='.$target.'&year='.substr((string)($_POST['punch_date']??date('Y-m-d')),0,4).'&month='.(int)substr((string)($_POST['punch_date']??date('Y-m-d')),5,2));
