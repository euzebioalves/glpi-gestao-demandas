<?php

declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use DBConnection;
use DateTimeImmutable;
use RuntimeException;
use Session;

final class TimeManagementService
{
    private $db;
    private array $settingsCache = [];
    private ?array $holidayRows = null;
    public function __construct() { $this->db = DBConnection::getReadConnection(); }

    public function settings(int $userId): array
    {
        if (isset($this->settingsCache[$userId])) return $this->settingsCache[$userId];
        foreach ($this->db->request(['FROM' => 'glpi_plugin_demandas_user_time_settings', 'WHERE' => ['users_id' => $userId], 'LIMIT' => 1]) as $row) return $this->settingsCache[$userId]=$row;
        return $this->settingsCache[$userId]=['users_id'=>$userId,'work_start'=>'08:00:00','work_end'=>'17:00:00','lunch_minutes'=>60,'tolerance_minutes'=>5,'working_days_json'=>'[1,2,3,4,5]','state_code'=>'','municipality'=>'','bank_initial_minutes'=>0,'bank_start_date'=>date('Y-01-01'),'openproject_user_href'=>'','openproject_user_name'=>''];
    }

    public function saveSettings(int $userId, array $input): void
    {
        $start = $this->time((string)($input['work_start'] ?? '08:00'));
        $end = $this->time((string)($input['work_end'] ?? '17:00'));
        if ($end <= $start) throw new RuntimeException('A hora de saída deve ser posterior à entrada.');
        $current = $this->settings($userId);
        $canMap = AccessPolicy::has(Profile::MANAGE_ATTENDANCE);
        $bankInitial=(int)($current['bank_initial_minutes']??0);
        $bankStart=(string)($current['bank_start_date']??'');
        if(array_key_exists('bank_initial_balance',$input)){
            $bankInitial=$this->historicalBalance((string)$input['bank_initial_balance']);
            if($bankInitial===0)$bankStart='';
            else{
                $bankStart=trim((string)($input['bank_start_date']??''));
                if($bankStart==='')throw new RuntimeException('Informe a data a partir da qual o saldo histórico será acumulado.');
                $bankStart=$this->date($bankStart);
            }
        }
        $data = [
            'users_id'=>$userId,'work_start'=>$start . ':00','work_end'=>$end . ':00',
            'lunch_minutes'=>max(0,min(360,(int)($input['lunch_minutes'] ?? 60))),
            'tolerance_minutes'=>max(0,min(60,(int)($input['tolerance_minutes'] ?? 5))),
            'working_days_json'=>json_encode(array_values(array_intersect([1,2,3,4,5,6,7],array_map('intval',(array)($input['working_days'] ?? [1,2,3,4,5]))))),
            'state_code'=>mb_strtoupper(mb_substr(trim((string)($input['state_code'] ?? '')),0,2)),
            'municipality'=>mb_substr(trim((string)($input['municipality'] ?? '')),0,120),
            'bank_initial_minutes'=>$bankInitial,'bank_start_date'=>$bankStart?:null,
            'openproject_user_href'=>$canMap ? trim((string)($input['openproject_user_href'] ?? '')) : (string)($current['openproject_user_href'] ?? ''),
            'openproject_user_name'=>$canMap ? mb_substr(trim((string)($input['openproject_user_name'] ?? '')),0,255) : (string)($current['openproject_user_name'] ?? ''),
            'date_mod'=>date('Y-m-d H:i:s'),
        ];
        $existing = $current;
        if (!empty($existing['id'])) $this->db->update('glpi_plugin_demandas_user_time_settings',$data,['id'=>(int)$existing['id']]);
        else { $data['date_creation']=date('Y-m-d H:i:s'); $this->db->insert('glpi_plugin_demandas_user_time_settings',$data); }
        unset($this->settingsCache[$userId]);
        $this->audit('settings.update',$userId,['settings'=>$data]);
    }

    public function savePunches(int $userId, string $date, array $times, string $note=''): int
    {
        $this->date($date); $count=0;
        foreach (array_unique(array_filter(array_map('trim',$times))) as $time) {
            $time=$this->time($time); $at=$date.' '.$time.':00';
            $exists=false; foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_punches','WHERE'=>['users_id'=>$userId,'punch_at'=>$at],'LIMIT'=>1]) as $_) {$exists=true;}
            if ($exists) continue;
            $this->db->insert('glpi_plugin_demandas_punches',['users_id'=>$userId,'punch_at'=>$at,'source'=>'manual','note'=>mb_substr($note,0,500),'created_by'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')]); $count++;
        }
        $this->audit('punches.create',$userId,['date'=>$date,'count'=>$count]); return $count;
    }

    public function saveDayPunches(int $userId, string $date, array $records): int
    {
        $this->date($date);
        $existing=[];
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_punches','WHERE'=>['users_id'=>$userId,['punch_at'=>['>=',$date.' 00:00:00']],['punch_at'=>['<=',$date.' 23:59:59']]]]) as $row) $existing[(int)$row['id']]=$row;
        $kept=[];$saved=0;$seenTimes=[];
        foreach($records as $record){
            if(!is_array($record))continue;
            $time=trim((string)($record['time']??''));if($time==='')continue;$time=$this->time($time);
            if(isset($seenTimes[$time]))throw new RuntimeException('Existem duas marcações no mesmo horário.');$seenTimes[$time]=true;
            $id=(int)($record['id']??0);$at=$date.' '.$time.':00';
            $data=['punch_at'=>$at,'punch_type'=>$this->punchType((string)($record['type']??'')),'nsr'=>mb_substr(trim((string)($record['nsr']??'')),0,50),'note'=>mb_substr(trim((string)($record['note']??'')),0,500)];
            if($id>0&&isset($existing[$id])){$this->db->update('glpi_plugin_demandas_punches',$data,['id'=>$id,'users_id'=>$userId]);$kept[$id]=true;}
            else{$data+=['users_id'=>$userId,'source'=>'manual','created_by'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')];$this->db->insert('glpi_plugin_demandas_punches',$data);}
            $saved++;
        }
        foreach($existing as $id=>$row)if(!isset($kept[$id]))$this->db->delete('glpi_plugin_demandas_punches',['id'=>$id,'users_id'=>$userId]);
        $this->audit('punches.sync',$userId,['date'=>$date,'count'=>$saved]);return $saved;
    }

    public function syncDayAbsence(int $userId,string $date,string $action,array $input,array $files=[]):void
    {
        $this->date($date);if(!in_array($action,['keep','set','remove'],true))$action='keep';if($action==='keep')return;
        $existing=[];foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absences','WHERE'=>['users_id'=>$userId,'absence_date'=>$date],'ORDER'=>['id ASC']])as$row)$existing[]=$row;
        if($action==='remove'){
            foreach($existing as$row)$this->deleteAbsence((int)$row['id']);
            $this->audit('absence.remove',$userId,['date'=>$date]);return;
        }
        $kind=(string)($input['absence_kind']??'unjustified');if(!in_array($kind,['justified','unjustified'],true))throw new RuntimeException('Tipo de falta inválido.');
        $whole=(string)($input['absence_scope']??'whole')==='whole';$settings=$this->settings($userId);
        $start=$whole?substr((string)$settings['work_start'],0,5):$this->time((string)($input['absence_start']??''));
        $end=$whole?substr((string)$settings['work_end'],0,5):$this->time((string)($input['absence_end']??''));
        $minutes=$this->minutesBetween($start,$end)-($whole?(int)$settings['lunch_minutes']:0);
        if($minutes<=0)throw new RuntimeException('O fim da falta deve ser posterior ao início.');
        $data=['users_id'=>$userId,'absence_date'=>$date,'kind'=>$kind,'minutes'=>$minutes,'starts_at'=>$start.':00','ends_at'=>$end.':00','is_full_day'=>$whole?1:0,'reason'=>mb_substr(trim((string)($input['absence_reason']??'')),0,2000),'created_by'=>(int)Session::getLoginUserID()];
        if($existing){$absenceId=(int)$existing[0]['id'];$this->db->update('glpi_plugin_demandas_absences',$data,['id'=>$absenceId]);foreach(array_slice($existing,1)as$row)$this->deleteAbsence((int)$row['id']);}
        else{$data['date_creation']=date('Y-m-d H:i:s');$this->db->insert('glpi_plugin_demandas_absences',$data);$absenceId=(int)$this->db->insertId();}
        if($kind==='justified'){foreach($files as$file)if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$this->storeAttachment($absenceId,$userId,$file);}else{$this->deleteAbsenceFiles($absenceId);}
        $this->audit('absence.sync',$userId,['absence_id'=>$absenceId,'date'=>$date,'kind'=>$kind,'full_day'=>$whole,'minutes'=>$minutes]);
    }

    public function saveAbsence(int $userId, array $input, ?array $file=null): void
    {
        $date=$this->date((string)($input['absence_date']??'')); $kind=(string)($input['kind']??'');
        if(!in_array($kind,['justified','unjustified'],true)) throw new RuntimeException('Tipo de ausência inválido.');
        $minutes=max(0,(int)($input['minutes']??0));
        $this->db->insert('glpi_plugin_demandas_absences',['users_id'=>$userId,'absence_date'=>$date,'kind'=>$kind,'minutes'=>$minutes ?: null,'reason'=>mb_substr(trim((string)($input['reason']??'')),0,2000),'created_by'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')]);
        $id=(int)$this->db->insertId();
        if($file && ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $this->storeAttachment($id,$userId,$file);
        $this->audit('absence.create',$userId,['absence_id'=>$id,'date'=>$date,'kind'=>$kind]);
    }

    public function month(int $userId, int $year, int $month): array
    {
        $first=sprintf('%04d-%02d-01',$year,$month); $last=(new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $p=[]; foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_punches','WHERE'=>['users_id'=>$userId,['punch_at'=>['>=',$first.' 00:00:00']],['punch_at'=>['<=',$last.' 23:59:59']]],'ORDER'=>['punch_at ASC']]) as $r) $p[substr($r['punch_at'],0,10)][]=$r;
        $a=[]; foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absences','WHERE'=>['users_id'=>$userId,['absence_date'=>['>=',$first]],['absence_date'=>['<=',$last]]],'ORDER'=>['absence_date ASC']]) as $r) $a[$r['absence_date']][]=$r;
        $days=[]; $cursor=new DateTimeImmutable($first); $end=new DateTimeImmutable($last);
        while($cursor<=$end){$d=$cursor->format('Y-m-d');$days[$d]=$this->calculateDay($userId,$d,$p[$d]??[],$a[$d]??[]);$cursor=$cursor->modify('+1 day');}
        return $days;
    }

    public function balance(int $userId,bool $completeOnly=false): int
    {
        $s=$this->settings($userId); $start=(string)($s['bank_start_date']?:date('Y-01-01')); $cursor=new DateTimeImmutable($start); $today=new DateTimeImmutable('today'); $end=$today->format('Y-m-d'); $total=(int)($s['bank_initial_minutes']??0);
        $punches=[];
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_punches','WHERE'=>['users_id'=>$userId,['punch_at'=>['>=',$start.' 00:00:00']],['punch_at'=>['<=',$end.' 23:59:59']]],'ORDER'=>['punch_at ASC']]) as $row){
            $punches[substr((string)$row['punch_at'],0,10)][]=$row;
        }
        $absences=[];
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absences','WHERE'=>['users_id'=>$userId,['absence_date'=>['>=',$start]],['absence_date'=>['<=',$end]]],'ORDER'=>['absence_date ASC']]) as $row){
            $absences[(string)$row['absence_date']][]=$row;
        }
        while($cursor<=$today){$d=$cursor->format('Y-m-d');$day=$this->calculateDay($userId,$d,$punches[$d]??[],$absences[$d]??[]);if(!$completeOnly||!$day['incomplete'])$total+=$day['balance'];$cursor=$cursor->modify('+1 day');}
        return $total;
    }

    /**
     * Retorna os fatos que efetivamente alteraram o banco de horas desde a
     * data-base configurada. O saldo histórico é mantido como o primeiro
     * lançamento para que a conferência tenha uma trilha contínua.
     */
    public function bankLedger(int $userId): array
    {
        $settings=$this->settings($userId);
        $start=(string)($settings['bank_start_date']?:date('Y-01-01'));
        $today=date('Y-m-d');
        $punches=[];
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_punches','WHERE'=>['users_id'=>$userId,['punch_at'=>['>=',$start.' 00:00:00']],['punch_at'=>['<=',$today.' 23:59:59']]],'ORDER'=>['punch_at ASC']]) as $row)$punches[substr((string)$row['punch_at'],0,10)][]=$row;
        $absences=[];
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absences','WHERE'=>['users_id'=>$userId,['absence_date'=>['>=',$start]],['absence_date'=>['<=',$today]]],'ORDER'=>['absence_date ASC']]) as $row)$absences[(string)$row['absence_date']][]=$row;

        $historical=(int)($settings['bank_initial_minutes']??0);
        $running=$historical;
        $entries=[[
            'date'=>$start,
            'kind'=>'historical',
            'label'=>'Saldo histórico informado',
            'details'=>'Saldo-base definido na jornada do usuário.',
            'minutes'=>$historical,
            'balance_after'=>$running,
        ]];
        $cursor=new DateTimeImmutable($start);
        $end=new DateTimeImmutable($today);
        while($cursor<=$end){
            $date=$cursor->format('Y-m-d');
            $day=$this->calculateDay($userId,$date,$punches[$date]??[],$absences[$date]??[]);
            $minutes=(int)$day['balance'];
            foreach($day['absences'] as $absence){
                if((string)$absence['kind']!=='justified')continue;
                $period=(int)($absence['is_full_day']??1)===1
                    ? 'Ausência justificada de dia inteiro.'
                    : 'Ausência justificada de '.substr((string)($absence['starts_at']??''),0,5).' às '.substr((string)($absence['ends_at']??''),0,5).'.';
                $reason=trim((string)($absence['reason']??''));
                $entries[]=['date'=>$date,'kind'=>'justified_absence','label'=>'Falta justificada','details'=>$period.($reason!==''?' Motivo: '.$reason:''),'minutes'=>0,'balance_after'=>$running,'neutral'=>true];
            }
            if($minutes!==0){
                $label=$minutes>0?'Crédito de jornada':'Débito de jornada';
                if(!empty($day['absences'])){
                    $kinds=array_unique(array_map(static fn(array $absence):string=>$absence['kind']==='unjustified'?'falta não justificada':'',$day['absences']));
                    $kinds=array_values(array_filter($kinds));
                    if($kinds!==[])$label=implode(' e ',$kinds);
                }elseif($day['incomplete']){
                    $label='Marcações incompletas';
                }
                $times=array_map(static fn(array $punch):string=>substr((string)$punch['punch_at'],11,5),$day['punches']);
                $details=$times!==[]?'Marcações: '.implode(' · ',$times).'.':'Sem marcação de ponto.';
                $running+=$minutes;
                $entries[]=['date'=>$date,'kind'=>'daily','label'=>$label,'details'=>$details,'minutes'=>$minutes,'balance_after'=>$running];
            }
            $cursor=$cursor->modify('+1 day');
        }
        return ['start_date'=>$start,'historical_minutes'=>$historical,'current_minutes'=>$running,'entries'=>$entries];
    }

    /** @return array<int, array<string, mixed>> */
    public function absences(int $userId,string $filter='all'): array
    {
        $where=['users_id'=>$userId];
        if(in_array($filter,['justified','unjustified'],true))$where['kind']=$filter;
        $rows=array_values(iterator_to_array($this->db->request(['FROM'=>'glpi_plugin_demandas_absences','WHERE'=>$where,'ORDER'=>['absence_date DESC','id DESC']])));
        $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);
        $files=[];
        if($ids!==[])foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absence_files','WHERE'=>['absences_id'=>$ids],'ORDER'=>['date_creation ASC','id ASC']])as$file)$files[(int)$file['absences_id']][]=$file;
        foreach($rows as &$row)$row['files']=$files[(int)$row['id']]??[];
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed> */
    public function absenceFile(int $fileId,int $userId): array
    {
        foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absence_files','WHERE'=>['id'=>$fileId,'users_id'=>$userId],'LIMIT'=>1])as$file)return $file;
        throw new RuntimeException('Anexo de ausência não encontrado.');
    }

    /** @return array<int, array<string, mixed>> */
    public function holidays(): array
    {
        $this->assertSuperAdmin();
        return array_values(iterator_to_array($this->db->request(['FROM'=>'glpi_plugin_demandas_holidays','ORDER'=>['holiday_date DESC','id DESC']])));
    }

    public function saveHoliday(array $input): int
    {
        $this->assertSuperAdmin();
        $name=mb_substr(trim((string)($input['name']??'')),0,255);
        if($name==='')throw new RuntimeException('Informe o nome do feriado ou dia não útil.');
        $date=$this->date((string)($input['holiday_date']??''));
        $scope=(string)($input['scope']??'national');
        if(!in_array($scope,['international','national','state','municipal'],true))throw new RuntimeException('Abrangência do dia não útil inválida.');
        $state=mb_strtoupper(mb_substr(trim((string)($input['state_code']??'')),0,2));
        $municipality=mb_substr(trim((string)($input['municipality']??'')),0,120);
        if($scope==='state'&&$state==='')throw new RuntimeException('Informe a UF para um feriado estadual.');
        if($scope==='municipal'&&($state===''||$municipality===''))throw new RuntimeException('Informe UF e município para um feriado municipal.');
        $substitute=trim((string)($input['substitute_date']??''));
        if($substitute!=='')$substitute=$this->date($substitute);
        $data=['name'=>$name,'holiday_date'=>$date,'scope'=>$scope,'state_code'=>$state,'municipality'=>$municipality,'is_working_day'=>0,'substitute_date'=>$substitute?:null,'notes'=>mb_substr(trim((string)($input['notes']??'')),0,2000)];
        $id=(int)($input['id']??0);
        if($id>0){
            $this->db->update('glpi_plugin_demandas_holidays',$data,['id'=>$id]);
        }else{
            $data+=['created_by'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')];
            $this->db->insert('glpi_plugin_demandas_holidays',$data);
            $id=(int)$this->db->insertId();
        }
        $this->holidayRows=null;
        $this->audit('holiday.save',0,['holiday_id'=>$id,'date'=>$date,'name'=>$name]);
        return $id;
    }

    public function deleteHoliday(int $holidayId): void
    {
        $this->assertSuperAdmin();
        if($holidayId<=0)throw new RuntimeException('Feriado inválido.');
        $this->db->delete('glpi_plugin_demandas_holidays',['id'=>$holidayId]);
        $this->holidayRows=null;
        $this->audit('holiday.delete',0,['holiday_id'=>$holidayId]);
    }

    private function calculateDay(int $userId,string $date,array $punches,array $absences): array
    {
        $punches=array_values($punches);$absences=array_values($absences);
        $s=$this->settings($userId); $weekday=(int)(new DateTimeImmutable($date))->format('N'); $workdays=json_decode((string)$s['working_days_json'],true)?:[1,2,3,4,5];
        $daily=max(0,$this->minutesBetween(substr($s['work_start'],0,5),substr($s['work_end'],0,5))-(int)$s['lunch_minutes']);
        $holiday=$this->holidayFor($userId,$date); $substitute=$this->isSubstituteDate($date);
        $nonWorkingDay=$holiday!==null||$substitute;
        $expected=in_array($weekday,$workdays,true)?$daily:0;
        $worked=0; for($i=0;$i+1<count($punches);$i+=2)$worked+=$this->minutesBetween(substr($punches[$i]['punch_at'],11,5),substr($punches[$i+1]['punch_at'],11,5));
        $hasJustified=false;$hasUnjustified=false;$justifiedMinutes=0;$unjustifiedMinutes=0;$fullJustified=false;
        foreach($absences as $absence){$hasJustified=$hasJustified||$absence['kind']==='justified';$hasUnjustified=$hasUnjustified||$absence['kind']==='unjustified';if($absence['kind']==='justified'){$justifiedMinutes+=max(0,(int)($absence['minutes']??0));$fullJustified=$fullJustified||(int)($absence['is_full_day']??1)===1;}if($absence['kind']==='unjustified')$unjustifiedMinutes+=max(0,(int)($absence['minutes']??0));}
        // Feriados e dias não úteis configurados não geram crédito nem débito.
        if($nonWorkingDay){$expected=0;$balance=0;}
        // Sem marcação ou ausência não há fato gerador para o banco de horas.
        elseif(count($punches)===0&&!$hasUnjustified){$expected=0;$balance=0;}
        elseif($fullJustified){$expected=0;$balance=0;}
        elseif($hasJustified){$expected=max(0,$expected-$justifiedMinutes);$balance=$worked-$expected;if(abs($balance)<=(int)$s['tolerance_minutes'])$balance=0;}
        elseif(count($punches)===0&&$hasUnjustified&&$unjustifiedMinutes>0){$expected=$unjustifiedMinutes;$balance=-$expected;}
        else{$balance=$worked-$expected;if(abs($balance)<=(int)$s['tolerance_minutes'])$balance=0;}
        return ['date'=>$date,'punches'=>$punches,'absences'=>$absences,'worked'=>$worked,'expected'=>$expected,'balance'=>$balance,'incomplete'=>count($punches)%2!==0,'holiday'=>$holiday,'substitute'=>$substitute,'non_working'=>$nonWorkingDay];
    }

    public function saveLocalTimeEntry(array $input,int $entryId=0):int
    {
        $user=(int)($input['users_id']??Session::getLoginUserID());$this->checkTimeEntryPermission($user);
        $wp=(int)($input['work_package_id']??0);$ticket=(int)($input['tickets_id']??0);$this->assertLinkedWorkPackage($ticket,$wp);
        $spent=$this->date((string)($input['spent_on']??''));$started=$this->time((string)($input['started_at']??''));$endRaw=trim((string)($input['ended_at']??''));$ended=$endRaw===''?'':$this->time($endRaw);
        $minutes=$ended===''?0:$this->minutesBetween($started,$ended);if($ended!==''&&$minutes<=0)throw new RuntimeException('O horário final deve ser posterior ao horário inicial.');
        $activityHref=(string)($input['activity_href']??'');$activityName=(string)($input['activity_name']??'');$this->assertActivity($wp,$activityHref);
        $data=['users_id'=>$user,'tickets_id'=>$ticket,'openproject_work_package_id'=>$wp,'spent_on'=>$spent,'started_at'=>$started.':00','ended_at'=>$ended===''?null:$ended.':00','minutes'=>$minutes,'activity_href'=>$activityHref,'activity_name'=>mb_substr($activityName,0,255),'comment'=>mb_substr(trim((string)($input['comment']??'')),0,2000),'date_mod'=>date('Y-m-d H:i:s')];
        if($entryId<=0){$data+=['sync_status'=>'pending','is_success'=>0,'error_message'=>null,'created_by'=>(int)Session::getLoginUserID(),'date_creation'=>date('Y-m-d H:i:s')];$this->db->insert('glpi_plugin_demandas_time_entries',$data);$entryId=(int)$this->db->insertId();$this->audit('time_entry.local_create',$user,['entry_id'=>$entryId,'wp'=>$wp]);return$entryId;}
        $entry=$this->timeEntry($entryId,$user);$opId=(int)($entry['openproject_time_entry_id']??0);
        if($opId>0){if($minutes<=0)throw new RuntimeException('Informe o horário final antes de atualizar uma entrada sincronizada.');$settings=$this->settings($user);OpenProjectClient::forCurrentUser()->updateTimeEntry($opId,$wp,$spent,$minutes,$activityHref,(string)$data['comment'],(string)($settings['openproject_user_href']??''));$data+=['sync_status'=>'synced','is_success'=>1,'error_message'=>null];}
        else{$data+=['sync_status'=>'pending','is_success'=>0,'error_message'=>null];}
        $this->db->update('glpi_plugin_demandas_time_entries',$data,['id'=>$entryId,'users_id'=>$user]);$this->audit('time_entry.update',$user,['entry_id'=>$entryId,'synced'=>$opId>0]);return$entryId;
    }

    public function syncTimeEntry(int $entryId,int $userId):void
    {
        $this->checkTimeEntryPermission($userId);$entry=$this->timeEntry($entryId,$userId);if((int)($entry['openproject_time_entry_id']??0)>0)return;if((int)$entry['minutes']<=0||empty($entry['ended_at']))throw new RuntimeException('Finalize a entrada informando o horário de término antes de sincronizar.');
        $settings=$this->settings($userId);
        try{$created=OpenProjectClient::forCurrentUser()->createTimeEntry((int)$entry['openproject_work_package_id'],(string)$entry['spent_on'],(int)$entry['minutes'],(string)$entry['activity_href'],(string)$entry['comment'],(string)($settings['openproject_user_href']??''));$opId=(int)($created['id']??0);if($opId<=0)throw new RuntimeException('O OpenProject não retornou o identificador da entrada de tempo.');$this->db->update('glpi_plugin_demandas_time_entries',['openproject_time_entry_id'=>$opId,'sync_status'=>'synced','is_success'=>1,'error_message'=>null,'date_mod'=>date('Y-m-d H:i:s')],['id'=>$entryId]);$this->audit('time_entry.sync',$userId,['entry_id'=>$entryId,'openproject_id'=>$opId]);}
        catch(\Throwable$e){$this->db->update('glpi_plugin_demandas_time_entries',['sync_status'=>'error','is_success'=>0,'error_message'=>$e->getMessage(),'date_mod'=>date('Y-m-d H:i:s')],['id'=>$entryId]);throw$e;}
    }

    public function finishTimeEntry(int $entryId,string $endedAt,int $userId):void
    {
        $this->checkTimeEntryPermission($userId);$entry=$this->timeEntry($entryId,$userId);if((int)($entry['openproject_time_entry_id']??0)>0)throw new RuntimeException('A entrada já foi sincronizada. Utilize a edição para alterar seu horário.');if(!empty($entry['ended_at']))throw new RuntimeException('A entrada já possui horário final.');$ended=$this->time($endedAt);$started=substr((string)$entry['started_at'],0,5);$minutes=$this->minutesBetween($started,$ended);if($minutes<=0)throw new RuntimeException('O horário final deve ser posterior ao horário inicial.');$this->db->update('glpi_plugin_demandas_time_entries',['ended_at'=>$ended.':00','minutes'=>$minutes,'sync_status'=>'pending','is_success'=>0,'error_message'=>null,'date_mod'=>date('Y-m-d H:i:s')],['id'=>$entryId,'users_id'=>$userId]);$this->audit('time_entry.finish',$userId,['entry_id'=>$entryId,'minutes'=>$minutes]);
    }

    public function deleteTimeEntry(int $entryId,int $userId):void
    {
        $this->checkTimeEntryPermission($userId);$entry=$this->timeEntry($entryId,$userId);$opId=(int)($entry['openproject_time_entry_id']??0);if($opId>0)OpenProjectClient::forCurrentUser()->deleteTimeEntry($opId);$this->db->delete('glpi_plugin_demandas_time_entries',['id'=>$entryId,'users_id'=>$userId]);$this->audit('time_entry.delete',$userId,['entry_id'=>$entryId,'openproject_id'=>$opId]);
    }

    private function timeEntry(int$id,int$userId):array{foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_time_entries','WHERE'=>['id'=>$id,'users_id'=>$userId],'LIMIT'=>1])as$row)return$row;throw new RuntimeException('Entrada de tempo não encontrada.');}
    private function checkTimeEntryPermission(int$user):void{if($user===(int)Session::getLoginUserID())AccessPolicy::check(Profile::LOG_OWN_TIME);else AccessPolicy::check(Profile::LOG_OTHERS_TIME);}
    private function assertLinkedWorkPackage(int$ticket,int$wp):void{$linked=false;foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_links','WHERE'=>['tickets_id'=>$ticket,'openproject_work_package_id'=>$wp],'LIMIT'=>1])as$_)$linked=true;if(!$linked)throw new RuntimeException('A Work Package não está vinculada ao chamado informado.');}
    private function assertActivity(int$wp,string$href):void{if($wp<=0||$href===''||!str_starts_with($href,'/api/v3/time_entries/activities/'))throw new RuntimeException('Selecione uma atividade válida para a Work Package.');}

    private function holidayRows():array{if($this->holidayRows===null)$this->holidayRows=array_values(iterator_to_array($this->db->request(['FROM'=>'glpi_plugin_demandas_holidays','ORDER'=>['scope DESC']])));return$this->holidayRows;}
    private function holidayFor(int $u,string $d):?array{$s=$this->settings($u);foreach($this->holidayRows()as$r){if((string)$r['holiday_date']!==$d)continue;if($r['scope']==='state'&&$r['state_code']!==$s['state_code'])continue;if($r['scope']==='municipal'&&mb_strtolower((string)$r['municipality'])!==mb_strtolower((string)$s['municipality']))continue;return$r;}return null;}
    private function isSubstituteDate(string $d):bool{foreach($this->holidayRows()as$r)if((string)($r['substitute_date']??'')===$d)return true;return false;}
    private function minutesBetween(string $a,string $b):int{[$ah,$am]=array_map('intval',explode(':',$a));[$bh,$bm]=array_map('intval',explode(':',$b));return max(0,$bh*60+$bm-$ah*60-$am);}
    private function historicalBalance(string $value):int
    {
        $value=str_replace(' ','',trim($value));
        if($value==='')return 0;
        if(!preg_match('/^(?<sign>[+-]?)(?<hours>\d{1,4})h(?<minutes>[0-5]\d)min$/i',$value,$matches))throw new RuntimeException('Informe o saldo histórico no formato +1h30min ou -0h45min.');
        $minutes=((int)$matches['hours']*60)+(int)$matches['minutes'];
        return ($matches['sign']??'')==='-'?-$minutes:$minutes;
    }
    private function assertSuperAdmin():void
    {
        if(!Config::isActiveSuperAdmin())throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    private function time(string $v):string{if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$v))throw new RuntimeException('Horário inválido.');return$v;}
    private function punchType(string $v):string{$allowed=['entrada_manha','saida_manha','entrada_tarde','saida_tarde','entrada_intermediaria','saida_intermediaria'];return in_array($v,$allowed,true)?$v:'';}
    private function date(string $v):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Data inválida.');return$v;}
    private function storeAttachment(int $absenceId,int $userId,array $file):void{$allowed=['pdf','png','jpg','jpeg','webp','doc','docx','xls','xlsx','odt','ods'];if((int)($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber um dos anexos.');$ext=mb_strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));if(!in_array($ext,$allowed,true)||($file['size']??0)>15*1024*1024)throw new RuntimeException('Anexo inválido ou maior que 15 MB.');$dir=GLPI_DOC_DIR.'/_plugins/demandas/absences';if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível preparar o diretório de anexos.');$stored=bin2hex(random_bytes(20)).'.'.$ext;if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('Não foi possível salvar o anexo.');$this->db->insert('glpi_plugin_demandas_absence_files',['absences_id'=>$absenceId,'users_id'=>$userId,'original_name'=>mb_substr((string)$file['name'],0,255),'stored_name'=>$stored,'mime_type'=>mb_substr((string)($file['type']??''),0,120),'file_size'=>(int)$file['size'],'date_creation'=>date('Y-m-d H:i:s')]);}
    private function deleteAbsenceFiles(int $absenceId):void{$dir=GLPI_DOC_DIR.'/_plugins/demandas/absences';foreach($this->db->request(['FROM'=>'glpi_plugin_demandas_absence_files','WHERE'=>['absences_id'=>$absenceId]])as$file){$path=$dir.'/'.basename((string)$file['stored_name']);if(is_file($path))@unlink($path);}$this->db->delete('glpi_plugin_demandas_absence_files',['absences_id'=>$absenceId]);}
    private function deleteAbsence(int $absenceId):void{$this->deleteAbsenceFiles($absenceId);$this->db->delete('glpi_plugin_demandas_absences',['id'=>$absenceId]);}
    private function audit(string $action,int $target,array $details):void{$this->db->insert('glpi_plugin_demandas_time_audit',['action'=>$action,'actor_users_id'=>(int)Session::getLoginUserID(),'target_users_id'=>$target,'details_json'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'date_creation'=>date('Y-m-d H:i:s')]);}
}
