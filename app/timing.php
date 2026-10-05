<?php
declare(strict_types=1);

/** Durations require both observed timestamps; unknown legacy times stay NULL. */
function visitTimingReport(array $input,int $page=1):array{
 if(!allowed('appointments.timing'))throw new DomainException('اجازه مشاهده گزارش زمان مراجعه را ندارید.');
 $f=appointmentFilters($input);
 if(user()['role']==='therapist')$f['therapist']=(int)user()['id'];
 [$where,$args]=appointmentWhere($f);
 $duration=static fn($a,$b)=>"CASE WHEN w.$b>=w.$a THEN TIMESTAMPDIFF(SECOND,w.$a,w.$b) END";
 $wait=$duration('arrived_at','treatment_started_at');$treatment=$duration('treatment_started_at','treatment_finished_at');$total=$duration('arrived_at','departed_at');
 $join=appointmentJoins();
 $summary=q("SELECT COUNT(*) visits,COUNT($wait) waiting_known,AVG($wait) waiting_average,COUNT($treatment) treatment_known,AVG($treatment) treatment_average,COUNT($total) total_known,AVG($total) total_average".$join."WHERE $where",$args)->fetch();
 $pages=max(1,(int)ceil((int)$summary['visits']/50));$page=max(1,min($pages,$page));
 $rows=q("SELECT s.id,s.starts_at,s.room,p.pid,p.fname,p.lname,u.name therapist_name,w.state,w.arrived_at,w.treatment_started_at,w.treatment_finished_at,w.departed_at,$wait waiting_seconds,$treatment treatment_seconds,$total total_seconds".$join."LEFT JOIN staff u ON u.id=s.therapist_id WHERE $where ORDER BY s.starts_at,s.id LIMIT 50 OFFSET ".(($page-1)*50),$args)->fetchAll();
 return compact('f','summary','rows','page','pages');
}
function timingMinutes($seconds):string{return $seconds===null?'ثبت نشده':fa(number_format((float)$seconds/60,1)).' دقیقه';}
function visitTimingView():void{
 demand('appointments.timing');
 try{$r=visitTimingReport($_GET,max(1,(int)($_GET['p']??1)));}catch(DomainException $ex){layout('appointments','گزارش زمان مراجعه');echo '<p class="notice">'.e($ex->getMessage()).'</p>';endLayout();return;}
 $f=$r['f'];layout('appointments','گزارش زمان مراجعه','زمان‌ها بر اساس ورود، شروع و پایان درمان و ترخیص ثبت‌شده محاسبه می‌شوند.');
 echo '<section class="panel form-panel"><form method="get"><div class="form-grid">';field('from','از تاریخ',$f['from'],'date');field('to','تا تاریخ',$f['to'],'date');
 if(user()['role']!=='therapist')selectfield('therapist','درمانگر',['0'=>'همه']+array_filter(therapists()),$f['therapist']);
 selectfield('clinic','کلینیک',['0'=>'همه']+options('clinics'),$f['clinic']);field('room','اتاق',$f['room']);selectfield('state','مرحله',[''=>'همه']+workflowLabels(),$f['state']);
 echo '</div><button class="button primary">نمایش گزارش</button></form></section><div class="stats-grid">';
 foreach(['waiting'=>'انتظار تا شروع درمان','treatment'=>'مدت درمان','total'=>'ورود تا ترخیص'] as $key=>$title)echo '<article class="stat-card"><span>میانگین '.e($title).'</span><strong>'.timingMinutes($r['summary'][$key.'_average']).'</strong><small>زمان معلوم: '.fa($r['summary'][$key.'_known']).' از '.fa($r['summary']['visits']).' مراجعه</small></article>';
 echo '</div><p class="hint">تاریخ فیلتر، تاریخ نوبت است. زمان‌های نامعلوم یا ناسازگار در میانگین وارد نمی‌شوند؛ ساعت نوبت جای زمان واقعی حضور را نمی‌گیرد.</p><section class="panel"><div class="table-scroll"><table><thead><tr><th>نوبت / بیمار</th><th>درمانگر / اتاق</th><th>ورود</th><th>شروع / پایان درمان</th><th>ترخیص</th><th>انتظار</th><th>درمان</th><th>کل حضور</th></tr></thead><tbody>';
 $stamp=static fn($v)=>$v===null?'ثبت نشده':jd($v,'yyyy/MM/dd HH:mm:ss');
 foreach($r['rows'] as $s){echo '<tr><td>'.jd($s['starts_at'],'yyyy/MM/dd HH:mm').'<small>'.e(patientName($s)).'</small></td><td>'.e($s['therapist_name']).'<small>'.e($s['room']).'</small></td><td>'.$stamp($s['arrived_at']).'</td><td>'.$stamp($s['treatment_started_at']).'<small>'.$stamp($s['treatment_finished_at']).'</small></td><td>'.$stamp($s['departed_at']).'</td>';foreach(['waiting','treatment','total'] as $key)echo '<td>'.timingMinutes($s[$key.'_seconds']).'</td>';echo '</tr>';}
 echo '</tbody></table></div><div class="actions">';
 foreach([$r['page']-1=>'صفحه قبل',$r['page']+1=>'صفحه بعد'] as $page=>$title)if($page>=1&&$page<=$r['pages'])echo '<a class="button secondary" href="'.e('/reports/timing?'.http_build_query(array_replace($f,['p'=>$page]))).'">'.$title.'</a>';
 echo '<span>صفحه '.fa($r['page']).' از '.fa($r['pages']).'</span></div></section>';endLayout();
}
