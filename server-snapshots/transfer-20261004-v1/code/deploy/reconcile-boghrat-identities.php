<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/import.php';

$actor=(int)(q("SELECT id FROM staff WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn()?:0);
if(!$actor){fwrite(STDERR,"No active admin user.\n");exit(2);}

$rows=q("SELECT r.*,p.fname,p.lname,p.national_id patient_national
         FROM import_records r
         JOIN patients p ON p.pid=r.pid
         WHERE r.pid IS NOT NULL
           AND r.pid IN (SELECT pid FROM import_records WHERE pid IS NOT NULL GROUP BY pid HAVING COUNT(*)>1)
         ORDER BY r.pid,r.id")->fetchAll();

$detach=[];$affected=[];
foreach($rows as $r){
    $sourceNational=preg_match('/^\d{10}$/D',(string)$r['national_id'])?(string)$r['national_id']:'';
    $patientNational=preg_match('/^\d{10}$/D',(string)$r['patient_national'])?(string)$r['patient_national']:'';
    $nationalConflict=$sourceNational!==''&&$patientNational!==''&&!hash_equals($sourceNational,$patientNational);
    $nationalConfirmed=$sourceNational!==''&&$patientNational!==''&&hash_equals($sourceNational,$patientNational);
    $nameMatch=importNormalizePersonName((string)$r['display_name'])===importNormalizePersonName(trim($r['fname'].' '.$r['lname']));
    if($nationalConflict||(!$nationalConfirmed&&!$nameMatch)){
        $detach[]=(int)$r['id'];
        $affected[(int)$r['pid']]=true;
    }
}

echo "AMBIGUOUS_RECORDS=".count($detach)."\n";
if(!$detach)exit(0);

$GLOBALS['db']->beginTransaction();
try{
    foreach($detach as $recordId){
        $oldPid=(int)q('SELECT pid FROM import_records WHERE id=? FOR UPDATE',[$recordId])->fetchColumn();
        q('UPDATE import_records SET pid=NULL WHERE id=?',[$recordId]);
        q('INSERT INTO audit(actor,action,entity) VALUES(?,?,?)',[$actor,'import_identity_detached',$recordId]);
        $result=importRegisterRecord($recordId,$actor);
        if(!in_array($result['status']??'',['created','linked'],true))throw new RuntimeException('relink_failed_'.$recordId.'_'.($result['status']??'unknown'));
        echo "RECORD=".$recordId." OLD_PID=".$oldPid." NEW_PID=".$result['pid']."\n";
    }

    foreach(array_keys($affected) as $pid){
        $isImported=(bool)q("SELECT id FROM audit WHERE entity=? AND action='import_patient_created' LIMIT 1",[$pid])->fetchColumn();
        if($isImported){
            q("UPDATE patients SET phone_cell=NULL,national_id=NULL,phone_home='',father_name='',DOB=NULL,marital_status='',referral_source='',occupation='',education='',height_cm=NULL,source_registered_date=NULL,clinic_registered_date=NULL,address='',medical_conditions='' WHERE pid=?",[$pid]);
        }
        $remaining=q('SELECT * FROM import_records WHERE pid=? ORDER BY id',[$pid])->fetchAll();
        foreach($remaining as $record)importApplyProfileToPatient((int)$pid,$record,$actor);
        echo "RESYNC_PID=".$pid." SOURCES=".count($remaining)."\n";
    }
    $GLOBALS['db']->commit();
}catch(Throwable $e){
    if($GLOBALS['db']->inTransaction())$GLOBALS['db']->rollBack();
    fwrite(STDERR,"reconcile_failed ".get_class($e)."\n");
    exit(3);
}
