<?php
declare(strict_types=1);

function receptionDiagnosisEdit(array $s):bool{
 return user()['role']==='admin'||((int)$s['therapist_id']===(int)user()['id']&&can('episodes'));
}
function receptionSelections(int $id,string $kind):array{
 [$table,$column]=match($kind){'labels'=>['session_labels','label_id'],'diagnoses'=>['session_diagnoses','diagnosis_id'],'packages'=>['session_packages','package_id'],default=>throw new DomainException('فهرست معتبر نیست.')};
 return array_map('intval',q("SELECT $column FROM $table WHERE session_id=? ORDER BY $column",[$id])->fetchAll(PDO::FETCH_COLUMN));
}
function receptionUpdate(int $id,int $version,string $kind,array $input):void{
 global $db;
 if(!in_array($kind,['labels','diagnoses','packages','notes'],true))throw new DomainException('نوع تغییر معتبر نیست.');
 $db->beginTransaction();
 try{
  $s=q('SELECT * FROM physio_sessions WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$s)throw new DomainException('مراجعه پیدا نشد.');workflowAccess($s);
  if($kind==='diagnoses'&&!receptionDiagnosisEdit($s))throw new DomainException('ثبت تشخیص فقط برای درمانگر مسئول یا مدیر مجاز است.');
  workflowEnsure($s);$w=q('SELECT * FROM visit_workflows WHERE session_id=? FOR UPDATE',[$id])->fetch();
  if((int)$w['version']!==$version)throw new DomainException('این مراجعه تغییر کرده است. صفحه را تازه کنید و دوباره ذخیره کنید.');
  $event=['actor_name'=>user()['name']];
  if($kind==='notes'){
   $note=$input['notes']??'';
   if(!is_string($note)||mb_strlen($note)>5000)throw new DomainException('توضیحات پذیرش حداکثر ۵۰۰۰ نویسه است.');
   $event['before_reception_notes']=$s['reception_notes']??'';$event['after_reception_notes']=trim($note);
   q('UPDATE physio_sessions SET reception_notes=? WHERE id=?',[trim($note),$id]);
  }else{
   [$table,$column,$catalog]=match($kind){'labels'=>['session_labels','label_id','labels'],'diagnoses'=>['session_diagnoses','diagnosis_id','diagnoses'],'packages'=>['session_packages','package_id','packages']};
   $old=receptionSelections($id,$kind);$values=$input['selection']??[];
   if(!is_array($values)||count($values)>30)throw new DomainException('حداکثر ۳۰ مورد انتخاب کنید.');
   $ids=[];foreach($values as $raw){$n=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$n)throw new DomainException('انتخاب معتبر نیست.');
    $active='active=1'.($kind==='diagnoses'?'':' AND deleted=0');
    if(!in_array($n,$old,true)&&!q("SELECT id FROM $catalog WHERE id=? AND $active",[$n])->fetchColumn())throw new DomainException('مورد فعال انتخاب کنید.');$ids[$n]=$n;
   }
   $new=$input['new_diagnosis']??'';if(!is_string($new)||mb_strlen($new)>255)throw new DomainException('عنوان تشخیص معتبر نیست.');$new=trim($new);
   if($new!==''){
    if($kind!=='diagnoses')throw new DomainException('درخواست معتبر نیست.');
    q('INSERT INTO diagnoses(name,active) VALUES(?,1) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$new]);
    $n=(int)q('SELECT id FROM diagnoses WHERE name=? AND active=1',[$new])->fetchColumn();if(!$n)throw new DomainException('این تشخیص غیرفعال است؛ با مدیر هماهنگ کنید.');$ids[$n]=$n;
   }
   if(count($ids)>30)throw new DomainException('حداکثر ۳۰ مورد انتخاب کنید.');
   q("DELETE FROM $table WHERE session_id=?",[$id]);foreach($ids as $n)q("INSERT INTO $table(session_id,$column) VALUES(?,?)",[$id,$n]);
   if($kind==='labels')q('UPDATE physio_sessions SET label_id=? WHERE id=?',[array_values($ids)[0]??null,$id]);
   if($kind==='packages')q('UPDATE physio_sessions SET package_id=? WHERE id=?',[array_values($ids)[0]??null,$id]);
   $event['before_'.$kind]=$old;$event['after_'.$kind]=array_values($ids);
  }
  q('UPDATE visit_workflows SET version=version+1,updated_at=NOW() WHERE session_id=?',[$id]);
  q("INSERT INTO visit_events(session_id,version,event_type,from_state,to_state,actor_id,source,details) VALUES(?,?,'appointment_updated',?,?,?,'staff',?)",[$id,$version+1,$w['state'],$w['state'],user()['id'],json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  audit('reception_'.$kind.'_updated',$id);$db->commit();
 }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();throw $ex;}
}
function receptionEditor(array $s,string $kind,string $title):void{
 $id=(int)$s['id'];$editable=allowed('appointments')&&(user()['role']!=='therapist'||(int)$s['therapist_id']===(int)user()['id'])&&($kind!=='diagnoses'||receptionDiagnosisEdit($s));
 if($kind==='diagnoses'&&!appointmentClinicalAllowed($s))return;
 $selected=$kind==='notes'?[]:receptionSelections($id,$kind);
 static $catalogs=[];
 if($kind!=='notes'&&!isset($catalogs[$kind])){
  $catalog=match($kind){'labels'=>'labels','diagnoses'=>'diagnoses','packages'=>'packages'};
  $catalogs[$kind]=q("SELECT id,name,active".($kind==='diagnoses'?'':',deleted')." FROM $catalog ORDER BY name")->fetchAll();
 }
 $names=[];foreach($catalogs[$kind]??[] as $entry)if(in_array((int)$entry['id'],$selected,true))$names[]=$entry['name'];
 if($kind==='diagnoses'&&!$names&&!empty($s['legacy_diagnosis']))$names[]=$s['legacy_diagnosis'];
 $summary=$kind==='notes'?trim($s['reception_notes']??''):implode('، ',$names);
 if(!$editable){echo '<span class="row-selection-text">'.e($summary?:'ثبت نشده').'</span>';return;}
 ?><details class="row-editor editor-<?=e($kind)?>" data-row-editor><summary><span class="editor-symbol"><?=match($kind){'labels'=>'#','diagnoses'=>'+','packages'=>'▦',default=>'✎'}?></span><span><strong><?=e($title)?></strong><small><?=e($summary!==''?mb_strimwidth($summary,0,85,'…'):'انتخاب / افزودن')?></small></span><span class="editor-chevron">⌄</span></summary>
 <form method="post" class="row-editor-body">
 <?php csrf();hidden('action','reception_update');hidden('session_id',$id);hidden('workflow_version',(int)($s['workflow_version']??0));hidden('kind',$kind); ?>
 <p class="editor-caption"><?=e(patientName($s))?> · <?=jd($s['starts_at'],'HH:mm')?></p>
 <?php if($kind==='notes'): ?>
 <label for="reception-notes-<?=$id?>">توضیحات پذیرش</label><textarea id="reception-notes-<?=$id?>" name="notes" rows="4" maxlength="5000" placeholder="توضیح موردنیاز پذیرش را بنویسید…"><?=e($s['reception_notes']??'')?></textarea><p class="hint">ویرایش‌ها با حفظ سابقه ذخیره می‌شوند.</p>
 <?php else: ?>
 <input type="search" data-choice-search aria-label="جستجو در <?=e($title)?>" placeholder="جستجو در <?=e($title)?>…" autocomplete="off">
 <span class="choice-counter" data-choice-counter role="status"></span><div class="choice-list">
 <?php foreach($catalogs[$kind] as $entry):$checked=in_array((int)$entry['id'],$selected,true);if((!$entry['active']||($entry['deleted']??false))&&!$checked)continue; ?>
 <label class="choice-option" data-choice-option><input type="checkbox" name="selection[]" value="<?=(int)$entry['id']?>" <?=$checked?'checked':''?>><span><?=e($entry['name'])?><?=!$entry['active']||($entry['deleted']??false)?' (غیرفعال)':''?></span></label>
 <?php endforeach; ?></div><p class="hint" data-choice-empty hidden>موردی با این عبارت پیدا نشد.</p>
 <?php if($kind==='diagnoses'): ?><label for="new-diagnosis-<?=$id?>">تشخیص جدید</label><input id="new-diagnosis-<?=$id?>" name="new_diagnosis" maxlength="255" placeholder="عنوان جدید؛ هم‌زمان ثبت و انتخاب می‌شود"><?php endif; ?>
 <?php if($kind==='packages'): ?><p class="hint">این انتخاب برای برنامه درمان مراجعه است. مبلغ صورتحساب در بخش مالی تعیین می‌شود.</p><?php endif; ?>
 <?php endif; ?><div class="editor-actions"><button type="submit" class="button primary small">ذخیره</button><button type="button" class="button secondary small" data-editor-cancel>انصراف</button></div>
 </form></details><?php
}
