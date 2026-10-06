"""Workflow regression tests against a disposable MariaDB, never the clinic DB."""
import json, os, pathlib, re, secrets, shutil, socket, subprocess, tempfile, time
import sys
import http.cookiejar, types, urllib.error, urllib.parse, urllib.request

class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, url, data=None, timeout=5):
        try:
            response = self.opener.open(url, data=urllib.parse.urlencode(data).encode() if data is not None else None, timeout=timeout)
        except urllib.error.HTTPError as error:
            response = error
        text = response.read().decode()
        return types.SimpleNamespace(status_code=response.code, text=text, url=response.geturl(), json=lambda: json.loads(text))

    def get(self, url, timeout=5):
        return self.request(url, timeout=timeout)

    def post(self, url, data, timeout=5):
        return self.request(url, data, timeout)

root = pathlib.Path(__file__).resolve().parent.parent
database = 'masiha_workflow_test_' + secrets.token_hex(4)
work = pathlib.Path(tempfile.mkdtemp(prefix='masiha-workflow-test-'))
env = {**os.environ, 'MASIHA_CONFIG': str(work / 'config.php')}
config = {'dsn': 'mysql:host=' + os.environ.get('MASIHA_TEST_DB_HOST', 'localhost') + ';dbname=' + database + ';charset=utf8mb4',
          'user': 'root', 'password': '', 'secret': secrets.token_hex(32), 'storage': str(work)}
cfg = subprocess.check_output(['php', '-r', '$c=json_decode(stream_get_contents(STDIN),true);echo "<?php return ".var_export($c,true).";";'], input=json.dumps(config), text=True)
(work / 'config.php').write_text(cfg)
(work / 'config.php').chmod(0o600)

def sql(text):
    return subprocess.check_output(['mariadb', '-N', '-e', text], text=True).strip()

def php(actor, code, patient=False):
    session = "$_SESSION=['" + ('pid' if patient else 'uid') + "'=>" + str(actor) + "];"
    return subprocess.check_output(['php', '-r', "require '" + str(root / 'app/bootstrap.php') + "';" + session + code], env=env, text=True)

def migrate():
    subprocess.run(['mariadb', database], input=(root / 'deploy/workflow.sql').read_text(), text=True, check=True)

def denied(actor, code, patient=False):
    output = php(actor, "try{" + code + ";echo 'BAD';}catch(DomainException $e){echo 'DENIED';}", patient)
    assert output == 'DENIED', output

try:
    sql('CREATE DATABASE ' + database + ' CHARACTER SET utf8mb4')
    for name in ['schema.sql', 'therapy.sql', 'portal.sql', 'admin-v2.sql', 'import.sql']:
        subprocess.run(['mariadb', database], input=(root / 'deploy' / name).read_text(), text=True, check=True)
    sql(f"""USE {database};
        INSERT INTO staff(id,username,name,password_hash,role) VALUES
        (101,'wf_admin','Admin','invalid','admin'),(102,'wf_therapist','Therapist','invalid','therapist'),
        (103,'wf_reception','Reception','invalid','reception'),(104,'wf_other','Other','invalid','therapist');
        INSERT INTO patients(pid,fname,lname,address,notes,allow_patient_portal) VALUES(901,'Test','Workflow','','','YES'),(902,'Other','Patient','','','YES');
        INSERT INTO physio_episodes(id,pid,diagnosis,body_region,assessment,goals,precautions,exercises,planned_sessions,created_by)
        VALUES(801,901,'test','test','','','','',10,103);
        INSERT INTO physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by,status,turn_state)
        VALUES(701,801,102,'2099-01-01 09:00:00','2099-01-01 09:30:00','test','','',103,'scheduled','none'),
              (702,801,102,'2099-01-02 09:00:00','2099-01-02 09:30:00','test','','',103,'done','done'),
              (703,801,102,'2099-01-03 09:00:00','2099-01-03 09:30:00','test','','',103,'scheduled','none');
        INSERT INTO physio_payments(episode_id,amount_toman,created_by) VALUES(801,500,103);
        """)
    subprocess.run(['mariadb',database],input=(root/'deploy/appointments.sql').read_text(),text=True,check=True)
    for _ in range(2):
        subprocess.run(['mariadb',database],input=(root/'deploy/history-reconciliation.sql').read_text()+(root/'deploy/billing.sql').read_text()+(root/'deploy/operations.sql').read_text(),text=True,check=True)
    before = sql(f'SELECT id,status,starts_at,ends_at,notes FROM {database}.physio_sessions ORDER BY id')
    migrate(); migrate()
    assert before == sql(f'SELECT id,status,starts_at,ends_at,notes FROM {database}.physio_sessions ORDER BY id')
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_events') == '3'
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_workflows WHERE arrived_at IS NOT NULL OR treatment_finished_at IS NOT NULL') == '0'
    assert sql(f'SELECT SUM(amount_toman) FROM {database}.physio_payments') == '500'
    print('PASS repeatable migration preserves old sessions and finance; no fabricated timestamps')

    denied(104, "workflowTransition(701,'waiting')")
    denied(103, "workflowTransition(701,'visited',[],null,['notes'=>'forbidden'])")
    denied(101, "workflowTransition(701,'visited',[],null,['notes'=>'not assigned'])")
    denied(902, "workflowTransition(703,'cancelled',[],null,null,true)", True)
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_events') == '3'
    print('PASS unrelated therapist, reception clinical result, unassigned admin and other patient denied')

    php(103, "workflowTransition(701,'waiting',[],0);workflowTransition(701,'waiting',[],1);")
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_events WHERE session_id=701') == '2'
    denied(103, "workflowTransition(701,'referred',[],0)")
    denied(103, "workflowTransition(701,'absent',[],1)")
    php(103, "workflowTransition(701,'referred',[],1);")
    denied(103, "workflowTransition(701,'absent',[],2)")
    php(103, "workflowTransition(701,'in_service',[],2);")
    denied(103, "workflowTransition(701,'discharged',[],3)")
    denied(103, "workflowTransition(701,'visited',[],3)")
    php(102, "workflowTransition(701,'visited',[],3,['pain_before'=>5,'pain_after'=>2,'rom'=>'120','notes'=>'result']);")
    php(103, "workflowTransition(701,'discharged',[],4);")
    denied(103, "workflowTransition(701,'waiting',[],5)")
    times_before = sql(f'SELECT arrived_at,treatment_started_at,treatment_finished_at,departed_at FROM {database}.visit_workflows WHERE session_id=701')
    php(102, "workflowTransition(701,'visited',[],5,['pain_before'=>5,'pain_after'=>1,'rom'=>'125','notes'=>'correction']);")
    assert times_before == sql(f'SELECT arrived_at,treatment_started_at,treatment_finished_at,departed_at FROM {database}.visit_workflows WHERE session_id=701')
    assert sql(f"SELECT state,version FROM {database}.visit_workflows WHERE session_id=701") == 'discharged\t6'
    assert sql(f"SELECT status,turn_state,pain_after FROM {database}.physio_sessions WHERE id=701") == 'done\tdone\t1'
    assert sql(f"SELECT COUNT(*) FROM {database}.visit_workflows WHERE session_id=701 AND arrived_at IS NOT NULL AND treatment_started_at IS NOT NULL AND treatment_finished_at IS NOT NULL AND departed_at IS NOT NULL") == '1'
    timeline = json.loads(php(102, "echo json_encode(workflowTimeline(701));"))
    assert timeline['events'][-1]['details']['before_result']['pain_after'] == 2
    assert timeline['waiting_seconds'] >= 0 and timeline['treatment_seconds'] >= 0
    hidden = json.loads(php(103, "echo json_encode(workflowTimeline(701));"))
    assert 'after_result' not in hidden['events'][-1]['details']
    denied(104, 'workflowTimeline(701)')
    print('PASS full visit lifecycle, stale-version rejection, idempotency, result correction, timestamps and clinical privacy')

    php(901, "workflowTransition(703,'cancelled',[],null,null,true);workflowTransition(703,'cancelled',[],null,null,true);", True)
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_events WHERE session_id=703') == '2'
    denied(103, "workflowTransition(703,'waiting')")
    assert sql(f"SELECT status FROM {database}.physio_sessions WHERE id=703") == 'cancelled'
    print('PASS patient cancellation and terminal-state protection')

    # Failure after a write must roll back state and event together.
    php(101, "q(\"INSERT INTO physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by) VALUES(704,801,102,'2099-01-04 09:00:00','2099-01-04 09:30:00','test','','',101)\");$db->beginTransaction();workflowBooked(704);$db->rollBack();")
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_workflows WHERE session_id=704') == '0'
    php(103, "try{workflowTransition(704,'waiting',['bad'=>NAN]);}catch(JsonException $e){echo 'ROLLBACK';}")
    assert sql(f"SELECT status,turn_state FROM {database}.physio_sessions WHERE id=704") == 'scheduled\tnone'
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_workflows WHERE session_id=704') == '0'
    print('PASS booking and failed-event transactions roll back without partial workflow')

    # An administrative correction appends an event and restores only observed state stamps.
    sql(f"INSERT INTO {database}.physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by) VALUES(705,801,102,'2099-01-05 09:00','2099-01-05 09:30','test','','',103)")
    php(103, "workflowTransition(705,'waiting',[],0);workflowTransition(705,'referred',[],1);workflowTransition(705,'in_service',[],2);")
    denied(103, "workflowReverse(705,3,'correction')")
    denied(101, "workflowReverse(705,3,'')")
    denied(101, "workflowReverse(705,2,'stale')")
    result=json.loads(php(101, "echo json_encode(workflowReverse(705,3,'incorrect click'));"))
    assert result['state']=='referred' and result['version']==4 and result['treatment_started_at'] is None
    assert result['arrived_at'] is not None
    denied(101, "workflowReverse(705,4,'cannot erase another event')")
    timeline=json.loads(php(101, "echo json_encode(workflowTimeline(705));"))
    assert timeline['events'][-1]['event_type']=='stage_corrected'
    assert timeline['events'][-1]['details']['reason']=='incorrect click'
    assert timeline['events'][-1]['details']['before_workflow']['treatment_started_at'] is not None
    assert timeline['events'][-1]['details']['after_workflow']['treatment_started_at'] is None
    assert timeline['events'][-1]['actor_name']=='Admin'
    denied(101, "workflowReverse(701,6,'cannot reopen completed visit')")
    assert sql(f"SELECT COUNT(*) FROM {database}.visit_events WHERE session_id=705")=='5'
    print('PASS authorized correction requires reason/version, retains original event, restores timestamps, protects completed treatment')

    # Appointment metadata is atomic and uses the same versioned audit as workflow changes.
    sql(f"USE {database}; INSERT INTO visit_types(id,name) VALUES(1,'Consultation'); INSERT INTO diagnoses(id,name) VALUES(1,'PRIVATE_DIAGNOSIS'); INSERT INTO labels(id,name) VALUES(1,'Label'); INSERT INTO resources(name,kind) VALUES('New room','room'); INSERT INTO packages(id,name) VALUES(1,'Package');")
    denied(103, "appointmentMetadata(705,4,['visit_type_id'=>1,'room'=>'New room','diagnoses'=>[1]])")
    assert sql(f"SELECT room FROM {database}.physio_sessions WHERE id=705")=='test'
    denied(103, "appointmentMetadata(705,4,['visit_type_id'=>1,'room'=>'New room','package_id'=>1])")
    php(103, "appointmentMetadata(705,4,['visit_type_id'=>1,'room'=>'New room','labels'=>[1]]);")
    denied(103, "appointmentMetadata(705,4,['visit_type_id'=>1,'room'=>'New room'])")
    php(102, "appointmentMetadata(705,5,['visit_type_id'=>1,'room'=>'New room','diagnoses'=>[1]]);")
    php(101, "appointmentMetadata(705,6,['visit_type_id'=>1,'room'=>'New room','package_id'=>1]);")
    timeline=json.loads(php(103, "echo json_encode(workflowTimeline(705));"))
    assert all('after_diagnoses' not in x['details'] for x in timeline['events'])
    assert sql(f"SELECT visit_type_id,room,package_id FROM {database}.physio_sessions WHERE id=705")=='1\tNew room\t1'
    assert sql(f"SELECT COUNT(*) FROM {database}.session_diagnoses WHERE session_id=705")=='1'
    result=json.loads(php(101,"echo json_encode(appointmentResults(appointmentFilters(['from'=>'2099-01-01','to'=>'2099-01-31','visit_type'=>'1','label'=>'1','diagnosis'=>'1','package'=>'1'])));"))
    assert result['count']==1 and result['rows'][0]['id']==705
    denied(103,"appointmentFilters(['diagnosis'=>'1'])")
    denied(104,"appointmentFilters(['financial'=>'debt'])")
    denied(103,"appointmentMetadata(701,6,['visit_type_id'=>1,'room'=>'New room'])")
    sql(f"USE {database}; INSERT INTO import_records(id,account_key,source_key,run_id,source_page,source_row,payload,payload_hash,bytes,pid) VALUES(1,'audit_fixture','legacy_fixture',0,1,1,'{{}}','hash',2,901); INSERT INTO import_patient_events(record_id,event_no,event_date,status_code,payload) VALUES(1,1,'2098-01-01','done','{{}}');")
    result=json.loads(php(101,"echo json_encode(appointmentResults(appointmentFilters(['from'=>'2099-01-01','to'=>'2099-01-01','patient_type'=>'returning'])));"))
    assert result['count']==1, 'imported completed visits must prevent new-patient misclassification'
    print('PASS appointment metadata rollback, stale version, diagnosis/package permissions, combined filters and private timeline')

    # Preferences are scoped to the signed-in user; role policy wins over user overrides.
    as_post="$v=displayDefaults();foreach($v as &$g){foreach($g as &$item){foreach(['visible','allowed','locked'] as $k)if(!$item[$k])unset($item[$k]);}unset($item);}unset($g);"
    denied(103, "displaySave([],true,'admin')")
    php(101, as_post+"unset($v['columns']['state']['visible']);$v['columns']['state']['locked']=1;unset($v['columns']['diagnoses']['allowed']);displaySave($v,true,'reception');")
    php(103, as_post+"unset($v['columns']['patient']['visible']);$v['columns']['time']['order']=1;displaySave($v);")
    prefs=json.loads(php(103,"echo json_encode(displayEffective());"))
    assert prefs['columns']['patient']['visible'] is False
    assert prefs['columns']['state']['visible'] is False and prefs['columns']['state']['locked'] is True
    assert prefs['columns']['diagnoses']['visible'] is False
    assert prefs['sidebar']['reports']['visible'] is False
    other_prefs=json.loads(php(104,"echo json_encode(displayEffective());"))
    assert other_prefs['columns']['patient']['visible'] is True
    php(103,"displaySave([],false,'',true);")
    assert json.loads(php(103,"echo json_encode(displayEffective());"))['columns']['patient']['visible'] is True
    php(101,"displaySave([],true,'reception',true);")
    print('PASS isolated display preferences, role locks, reset, forged-policy denial and permission ceiling')

    # Suggestions obey schedules, absence and both therapist/patient/resource conflicts.
    schedule="['therapist_id'=>102,'clinic_id'=>1,'weekday'=>clinicWeekday('2099-03-01'),'start_time'=>'09:00:00','end_time'=>'12:00:00','effective_from'=>'2099-03-01','effective_to'=>'2099-03-01']"
    denied(104,'saveTherapistSchedule('+schedule+')')
    php(101,'saveTherapistSchedule('+schedule+');')
    denied(101,'saveTherapistSchedule('+schedule+')')
    php(101,"saveTherapistAbsence(102,'2099-03-01 10:00:00','2099-03-01 11:00:00','Away');")
    sql(f"INSERT INTO {database}.physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by) VALUES(710,801,102,'2099-03-01 09:00','2099-03-01 09:30','New room','','',103)")
    slots=json.loads(php(103,"echo json_encode(appointmentSlotSuggestions(901,102,1,'2099-03-01',30,'New room',1));"))
    assert slots[0]['starts_at']=='2099-03-01 09:30:00'
    assert all(x['ends_at']<='2099-03-01 10:00:00' or x['starts_at']>='2099-03-01 11:00:00' for x in slots)
    denied(101,"saveTherapistAbsence(102,'2099-03-01 09:00:00','2099-03-01 09:30:00','Booked interval')")
    denied(104,"appointmentSlotSuggestions(901,102,1,'2099-03-01',30)")
    assert json.loads(php(101,"echo json_encode(appointmentSlotSuggestions(901,104,1,'2099-03-02',30,'',1));"))==[]
    # A concurrent absence waits for a committed booking and then refuses the overlap.
    signal=work/'booking-lock-ready'
    lock_code="require '"+str(root/'app/bootstrap.php')+"';$_SESSION=['uid'=>101];schedulingTransaction(function(){q(\"INSERT INTO physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by) VALUES(711,801,102,'2099-03-01 11:00','2099-03-01 11:30','New room','','',101)\");file_put_contents('"+str(signal)+"','ready');usleep(600000);});"
    booking=subprocess.Popen(['php','-r',lock_code],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        for _ in range(100):
            if signal.exists():break
            if booking.poll() is not None:raise AssertionError(booking.communicate())
            time.sleep(.01)
        assert signal.exists()
        denied(101,"saveTherapistAbsence(102,'2099-03-01 11:00:00','2099-03-01 11:30:00','Concurrent')")
        assert booking.wait(timeout=5)==0
    finally:
        if booking.poll() is None:booking.kill();booking.wait()
    # A conflicting copy must not leave any of its earlier inserts behind.
    php(101,"saveTherapistSchedule(['therapist_id'=>102,'clinic_id'=>1,'weekday'=>clinicWeekday('2099-03-02'),'start_time'=>'09:00:00','end_time'=>'12:00:00','effective_from'=>'2099-03-02','effective_to'=>'2099-03-02']);saveTherapistSchedule(['therapist_id'=>104,'clinic_id'=>1,'weekday'=>clinicWeekday('2099-03-02'),'start_time'=>'10:00:00','end_time'=>'13:00:00','effective_from'=>'2099-03-02','effective_to'=>'2099-03-02']);")
    denied(101,"copyTherapistSchedule(102,104)")
    assert sql(f'SELECT COUNT(*) FROM {database}.therapist_schedules WHERE therapist_id=104')=='1'
    defaults=json.loads(php(103,"echo json_encode(nextAppointmentDefaults(705,901));"))
    assert defaults['therapist_id']==102 and defaults['room']=='New room'
    denied(103,"nextAppointmentDefaults(705,902)")
    print('PASS actual free slots, no invented hours, absence/booking locking race, schedule collision and next-visit ownership')

    # Report excludes unknown/invalid observed durations from averages, with separate permission.
    sql(f"""INSERT INTO {database}.visit_workflows(session_id,state,arrived_at,treatment_started_at,treatment_finished_at,departed_at)
        VALUES(710,'discharged','2099-03-01 09:00','2099-03-01 09:10','2099-03-01 09:30','2099-03-01 09:35'),
              (711,'scheduled',NULL,NULL,NULL,NULL)""")
    report_query="visitTimingReport(['from'=>'2099-03-01','to'=>'2099-03-01'])"
    denied(103,report_query)
    report=json.loads(php(101,'echo json_encode('+report_query+');'))
    assert int(report['summary']['visits'])==2 and int(report['summary']['waiting_known'])==1
    assert float(report['summary']['waiting_average'])==600 and float(report['summary']['treatment_average'])==1200
    assert float(report['summary']['total_average'])==2100 and report['rows'][1]['total_seconds'] is None
    sql(f"UPDATE {database}.visit_workflows SET arrived_at='2099-03-01 11:30',treatment_started_at='2099-03-01 11:00' WHERE session_id=711")
    report=json.loads(php(101,'echo json_encode('+report_query+');'))
    assert int(report['summary']['waiting_known'])==1 and report['rows'][1]['waiting_seconds'] is None
    php(101,"$p=defaultsFor('therapist');$p['appointments.timing']=true;q('INSERT INTO staff_permissions(staff_id,permissions) VALUES(?,?)',[104,json_encode($p)]);")
    report=json.loads(php(104,"echo json_encode(visitTimingReport(['from'=>'2099-03-01','to'=>'2099-03-01','therapist'=>102]));"))
    assert int(report['summary']['visits'])==0 and report['f']['therapist']==104
    sql(f'DELETE FROM {database}.staff_permissions WHERE staff_id=104')
    print('PASS timing report range, real durations, unknown/invalid exclusion and independent permission/therapist scope')

    # The tenth-session milestone counts completed rows in this course, not absence/cancellation/events.
    sql(f"INSERT INTO {database}.physio_episodes(id,pid,diagnosis,body_region,assessment,goals,precautions,exercises,planned_sessions,created_by) VALUES(802,902,'count','test','','','','',120,101)")
    for i in range(103):
        status='done' if i<9 else ('absent' if i%2 else 'cancelled')
        sql(f"INSERT INTO {database}.physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by,status) VALUES({1000+i},802,101,'2099-05-01 09:00','2099-05-01 09:30','','','',101,'{status}')")
    progress=json.loads(php(101,'echo json_encode(episodeCompletion(802));'))
    assert progress['done']==9 and not progress['tenth_reached']
    denied(104,'episodeCompletion(802)')
    sql(f"UPDATE {database}.physio_sessions SET status='done' WHERE id=1102")
    progress=json.loads(php(101,'echo json_encode(episodeCompletion(802));'))
    assert progress['done']==10 and progress['remaining']==110 and progress['tenth_reached']
    php(101,"workflowTransition(1102,'visited',[],null,['notes'=>'correction']);workflowTransition(1102,'visited',[],null,['notes'=>'second correction']);")
    assert json.loads(php(101,'echo json_encode(episodeCompletion(802));'))['done']==10
    print('PASS tenth completed course session excludes absent/cancelled rows, counts beyond pagination and survives result corrections')

    # Imported history is counted only after explicit, versioned identity/visit reconciliation.
    sql(f"""INSERT INTO {database}.import_records(id,account_key,source_key,run_id,source_page,source_row,payload,payload_hash,bytes,pid)
      VALUES(501,REPEAT('a',64),REPEAT('b',64),1,1,1,'{{}}',REPEAT('c',64),2,901),
            (502,REPEAT('a',64),REPEAT('d',64),1,1,2,'{{}}',REPEAT('e',64),2,901),
            (503,REPEAT('a',64),REPEAT('f',64),1,1,3,'{{}}',REPEAT('f',64),2,902);
      INSERT INTO {database}.import_patient_events(record_id,event_no,appointment_code,event_date,status_code,notes,services,goods,payload)
      VALUES(501,1,'۱۲۳','2098-01-01','done','','','','{{}}'),(502,1,'123','2098-01-01','done','','','','{{}}'),
            (501,2,'456','2099-01-01','done','','','','{{}}'),(501,3,'789','2098-02-01','absent','','','','{{}}'),
            (501,4,'','2098-03-01','done','','','','{{}}');""")
    def history():
        return json.loads(php(101,'echo json_encode(patientCompletedHistory(901));'))
    def review(group,decision='historical',session=0,version=0,actor=101):
        code=f"reviewCompletedHistory(901,'{group['source_hash']}','{group['evidence_hash']}',{version},'{decision}',{session},'Verified synthetic source');"
        return php(actor,code)
    hist=history();native_count=hist['native']
    assert hist['pending']==4 and hist['historical']==0 and hist['duplicate_rows']==1 and not hist['complete']
    groups={g['code']:g for g in hist['groups'].values()}
    denied(103,f"reviewCompletedHistory(901,'{groups['123']['source_hash']}','{groups['123']['evidence_hash']}',0,'historical',0,'Denied')")
    review(groups['123']);review(groups['456'],'native',701)
    hist=history();assert hist['verified_total']==native_count+1 and hist['mapped']==1 and hist['pending']==2
    denied(101,f"reviewCompletedHistory(901,'{groups['123']['source_hash']}','{groups['123']['evidence_hash']}',0,'exclude',0,'stale')")
    denied(101,f"reviewCompletedHistory(901,'{groups['123']['source_hash']}','{groups['123']['evidence_hash']}',1,'native',1102,'other patient')")
    denied(101,f"reviewCompletedHistory(901,'{groups['123']['source_hash']}','{groups['123']['evidence_hash']}',1,'native',701,'duplicate link')")
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_history_reviews')=='2'
    # Re-import with changed evidence invalidates the old decision without deleting it.
    sql(f"UPDATE {database}.import_patient_events SET event_date='2098-01-02' WHERE appointment_code='123'")
    hist=history();assert hist['historical']==0 and hist['pending']==3
    # An appointment code assigned to two different patients must never be merged.
    sql(f"INSERT INTO {database}.import_patient_events(record_id,event_no,appointment_code,event_date,status_code,notes,services,goods,payload) VALUES(503,1,'123','2098-01-01','done','','','','{{}}')")
    group=next(g for g in history()['groups'].values() if g['code']=='123')
    assert group['identity_conflict']
    denied(101,f"reviewCompletedHistory(901,'{group['source_hash']}','{group['evidence_hash']}',1,'historical',0,'conflict')")
    assert sql(f'SELECT COUNT(*) FROM {database}.visit_history_reviews')=='2'
    print('PASS imported/native history deduplication, Persian codes, uncoded/changed evidence, stale reviews, identity/permission isolation and immutable audit')

    # Native billing is opt-in, versioned, snapshot-based and independent from imported finance.
    sql(f"""INSERT INTO {database}.physio_episodes(id,pid,diagnosis,body_region,assessment,goals,precautions,exercises,planned_sessions,fee_toman,created_by)
        VALUES(803,901,'billing','test','','','','',10,888,101);
        INSERT INTO {database}.physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by,price_snapshot)
        VALUES(1200,803,102,'2099-06-01 09:00','2099-06-01 09:30','','','',101,1001),
              (1201,803,102,'2099-06-02 09:00','2099-06-02 09:30','','','',101,999),
              (1202,803,102,'2099-06-03 09:00','2099-06-03 09:30','','','',101,500),
              (1203,803,102,'2099-06-04 09:00','2099-06-04 09:30','','','',101,500);""")
    denied(103,"discountCategorySave(0,'denied','percent',10,true)")
    denied(101,"discountCategorySave(0,'bad','percent',101,true)")
    discount=int(php(101,"echo discountCategorySave(0,'Synthetic discount','percent',10,true);"))
    denied(103,"billingSave(803,0,'manual',100,0,0,'denied')")
    php(101,f"billingSave(803,0,'services',0,{discount},0,'Opt into performed service billing');")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='0'
    php(102,"workflowTransition(1200,'visited',[],0,['notes'=>'completed']);")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='901'
    invoice_version=sql(f'SELECT version FROM {database}.episode_billing WHERE episode_id=803')
    php(102,"workflowTransition(1200,'visited',[],1,['notes'=>'corrected result']);")
    assert sql(f'SELECT version FROM {database}.episode_billing WHERE episode_id=803')==invoice_version
    php(101,f"discountCategorySave({discount},'Changed catalog','percent',50,true);")
    php(102,"workflowTransition(1201,'visited',[],0,['notes'=>'completed']);")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='1800'
    denied(101,"billingSave(803,1,'manual',999,0,0,'stale')")
    # Two completed visits serialize on the course and neither charge is lost.
    signal=work/'billing-ready'
    code="require '"+str(root/'app/bootstrap.php')+"';$_SESSION=['uid'=>102];$db->beginTransaction();workflowTransition(1202,'visited',[],0,['notes'=>'concurrent one']);file_put_contents('"+str(signal)+"','ready');usleep(600000);$db->commit();"
    job=subprocess.Popen(['php','-r',code],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        for _ in range(100):
            if signal.exists():break
            if job.poll() is not None:raise AssertionError(job.communicate())
            time.sleep(.01)
        assert signal.exists()
        php(102,"workflowTransition(1203,'visited',[],0,['notes'=>'concurrent two']);")
        assert job.wait(timeout=5)==0
    finally:
        if job.poll() is None:job.kill();job.wait()
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='2700'
    # A later failure rolls back both the clinical result and derived invoice/audit.
    sql(f"INSERT INTO {database}.physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by,price_snapshot) VALUES(1204,803,102,'2099-06-05 09:00','2099-06-05 09:30','','','',101,500)")
    before_billing=sql(f'SELECT COUNT(*) FROM {database}.billing_events')
    php(102,"try{workflowTransition(1204,'visited',['bad'=>NAN],0,['notes'=>'rollback']);}catch(JsonException $e){echo 'ROLLBACK';}")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='2700'
    assert sql(f'SELECT status FROM {database}.physio_sessions WHERE id=1204')=='scheduled'
    assert sql(f'SELECT COUNT(*) FROM {database}.billing_events')==before_billing
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=801')=='0'
    # Fixed package snapshots do not drift when the catalog changes.
    sql(f"INSERT IGNORE INTO {database}.package_items(package_id,service_id,quantity) VALUES(1,1,10); UPDATE {database}.packages SET price=5000 WHERE id=1")
    sql(f'UPDATE {database}.physio_sessions SET service_id=1 WHERE episode_id=803')
    version=int(sql(f'SELECT version FROM {database}.episode_billing WHERE episode_id=803'))
    php(101,f"billingSave(803,{version},'package',0,0,1,'Agreed package');")
    sql(f'UPDATE {database}.packages SET price=9000 WHERE id=1')
    php(102,"workflowTransition(1204,'visited',[],0,['notes'=>'complete under package']);")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='5000'
    assert json.loads(php(101,"echo json_encode(billingAmounts(5,'percent',10));"))['discount_toman']==1
    assert json.loads(php(101,"echo json_encode(billingAmounts(100,'fixed',200));"))['net_toman']==0
    print('PASS opt-in native invoices, permissions, discount/package snapshots, rounding, stale forms, concurrent completions and clinical/financial rollback')

    # Package reservations are checked against the stored allowance, not changed catalog quantities.
    sql(f"UPDATE {database}.episode_billing SET package_snapshot=JSON_SET(package_snapshot,'$.items[0].quantity',5) WHERE episode_id=803")
    output=php(101,"try{$db->beginTransaction();q(\"INSERT INTO physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by,service_id) VALUES(1210,803,102,'2099-07-01 09:00','2099-07-01 09:30','','','',101,1)\");workflowBooked(1210);$db->commit();echo 'BAD';}catch(DomainException $e){$db->rollBack();echo 'DENIED';}")
    assert output=='DENIED' and sql(f'SELECT COUNT(*) FROM {database}.physio_sessions WHERE id=1210')=='0'
    sql(f'UPDATE {database}.package_items SET quantity=1 WHERE package_id=1')
    version=int(sql(f'SELECT version FROM {database}.episode_billing WHERE episode_id=803'))
    denied(101,f"billingSave(803,{version},'package',0,0,1,'too small')")
    assert sql(f'SELECT fee_toman FROM {database}.physio_episodes WHERE id=803')=='5000'
    # Exact thousandth-unit stock, unknown opening, repeat-safe consumption and compensating returns.
    sql(f"INSERT INTO {database}.inventory_items(id,name,unit) VALUES(990,'Synthetic consumable','unit'),(991,'Unknown','unit')")
    denied(101,"stockMove(991,'-1','2026-10-05','unknown','"+'1'*32+"')")
    php(101,"stockOpening(990,'2.500','2026-01-01');")
    denied(101,"stockOpening(990,'50','2026-01-01')")
    denied(104,"stockMove(990,'-1','2026-10-05','outside','"+'2'*32+"',701)")
    token='3'*32
    move=int(php(102,f"echo stockMove(990,'-1.125','2026-10-05','used','{token}',701);"))
    assert int(php(102,f"echo stockMove(990,'-1.125','2026-10-05','used','{token}',701);"))==move
    denied(102,"stockMove(990,'-2','2026-10-05','insufficient','"+'4'*32+"',701)")
    php(101,f"stockMove(990,'1.125','2026-10-05','return','"+'5'*32+f"',701,{move});")
    denied(101,f"stockMove(990,'1.125','2026-10-05','second return','"+'6'*32+f"',701,{move})")
    assert sql(f'SELECT SUM(quantity_delta) FROM {database}.inventory_movements WHERE item_id=990')=='0.000'
    # A competing stock writer waits on the item and sees the committed balance.
    signal=work/'stock-ready'
    code="require '"+str(root/'app/bootstrap.php')+"';$db->beginTransaction();q('SELECT id FROM inventory_items WHERE id=990 FOR UPDATE');q(\"INSERT INTO inventory_movements(item_id,movement_type,quantity_delta,occurred_on,affects_stock,note) VALUES(990,'adjustment',-2,'2026-10-05',1,'synthetic concurrent')\");file_put_contents('"+str(signal)+"','ready');usleep(600000);$db->commit();"
    job=subprocess.Popen(['php','-r',code],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        for _ in range(100):
            if signal.exists():break
            if job.poll() is not None:raise AssertionError(job.communicate())
            time.sleep(.01)
        assert signal.exists()
        denied(101,"stockMove(990,'-1','2026-10-05','competing','"+'7'*32+"')")
        assert job.wait(timeout=5)==0
    finally:
        if job.poll() is None:job.kill();job.wait()
    # Document types extend without changing old documents and classification is patient-scoped.
    php(101,"documentTypeSave(0,'Synthetic type',true);")
    doc_type=int(sql(f'SELECT MAX(id) FROM {database}.document_types'))
    sql(f"INSERT INTO {database}.physio_patient_documents(id,pid,title,storage_name,mime,bytes) VALUES(991,902,'Synthetic','unused-synthetic','application/pdf',1)")
    denied(104,f'classifyDocument(991,{doc_type})')
    php(101,f'classifyDocument(991,{doc_type});')
    assert sql(f'SELECT COUNT(*) FROM {database}.document_events WHERE document_id=991')=='1'
    # Preview never sends. Queue has explicit enabled gate, owner, membership and repeat protection.
    sql(f"UPDATE {database}.patients SET phone_cell='09120000001' WHERE pid=901; UPDATE {database}.patients SET phone_cell='09120000002' WHERE pid=902")
    preview=php(101,"echo audiencePreview(['target_pid'=>901],'Synthetic','Test message');")
    denied(101,f"audienceQueue('{preview}')")
    php(101,"setsetting('sms_notifications','1');")
    denied(103,f"audienceQueue('{preview}')")
    campaign=int(php(101,f"echo audienceQueue('{preview}');"))
    assert int(php(101,f"echo audienceQueue('{preview}');"))==campaign
    assert sql(f"SELECT queued_count FROM {database}.sms_campaigns WHERE id={campaign}")=='1'
    assert sql(f"SELECT COUNT(*) FROM {database}.sms_outbox WHERE message='Test message'")=='1'
    preview2=php(101,"echo audiencePreview(['target_pid'=>901],'Synthetic changed','No send');")
    sql(f"UPDATE {database}.patients SET phone_cell='09120000003' WHERE pid=901")
    denied(101,f"audienceQueue('{preview2}')")
    php(101,"setsetting('sms_notifications','0');")
    # Clinical report and progress permission ceiling.
    denied(103,"clinicalReportData(['from'=>'2099-06-01','to'=>'2099-06-30'])")
    denied(103,'progressData(901)')
    report=json.loads(php(101,"echo json_encode(clinicalReportData(['from'=>'2099-06-01','to'=>'2099-06-30']));"))
    assert sum(int(x['completed']) for x in report['rows'])==5
    assert json.loads(php(102,'echo json_encode(progressData(901));'))['count']>0
    print('PASS package allowance rollback, atomic exact stock/returns/concurrency, document scopes, preview-only SMS/queue guards and clinical reports')

    # Reception edits: multi-selection, versioning, clinical privacy and finance isolation.
    sql(f"INSERT INTO {database}.packages(id,name,price) VALUES(880,'Second package',250000)")
    version=int(sql(f"SELECT version FROM {database}.visit_workflows WHERE session_id=705"))
    original=sql(f"SELECT notes FROM {database}.physio_sessions WHERE id=705")
    fees=sql(f"SELECT SUM(fee_toman) FROM {database}.physio_episodes")
    denied(104,f"receptionUpdate(705,{version},'notes',['notes'=>'forbidden'])")
    denied(103,f"receptionUpdate(705,{version},'diagnoses',['selection'=>[1]])")
    php(103,f"receptionUpdate(705,{version},'packages',['selection'=>[1,880,880]]);")
    assert sql(f"SELECT COUNT(*) FROM {database}.session_packages WHERE session_id=705")=='2'
    assert sql(f"SELECT SUM(fee_toman) FROM {database}.physio_episodes")==fees
    denied(103,f"receptionUpdate(705,{version},'notes',['notes'=>'stale'])")
    version+=1
    denied(103,f"receptionUpdate(705,{version},'packages',['selection'=>[999999]])")
    assert sql(f"SELECT COUNT(*) FROM {database}.session_packages WHERE session_id=705")=='2'
    found=json.loads(php(103,"echo json_encode(appointmentResults(appointmentFilters(['from'=>'2099-01-01','to'=>'2099-01-31','package'=>880])));"))
    assert any(int(row['id'])==705 for row in found['rows'])
    php(103,f"receptionUpdate(705,{version},'notes',['notes'=>'Reception-only note']);")
    version+=1
    assert sql(f"SELECT reception_notes FROM {database}.physio_sessions WHERE id=705")=='Reception-only note'
    assert sql(f"SELECT notes FROM {database}.physio_sessions WHERE id=705")==original
    php(102,f"receptionUpdate(705,{version},'diagnoses',['selection'=>[1],'new_diagnosis'=>'Synthetic new diagnosis']);")
    version+=1
    assert sql(f"SELECT COUNT(*) FROM {database}.session_diagnoses WHERE session_id=705")=='2'
    timeline=json.loads(php(103,"echo json_encode(workflowTimeline(705));"))
    assert all('after_diagnoses' not in item['details'] for item in timeline['events'])
    php(103,f"receptionUpdate(705,{version},'labels',['selection'=>[]]);")
    version+=1
    assert sql(f"SELECT COUNT(*) FROM {database}.session_labels WHERE session_id=705")=='0'
    assert sql(f"SELECT label_id IS NULL FROM {database}.physio_sessions WHERE id=705")=='1'
    # Repeat migrations must not resurrect removed labels or lose additional packages.
    subprocess.run(['mariadb',database],input=(root/'deploy/appointments.sql').read_text(),text=True,check=True)
    assert sql(f"SELECT COUNT(*) FROM {database}.session_packages WHERE session_id=705")=='2'
    assert sql(f"SELECT COUNT(*) FROM {database}.session_labels WHERE session_id=705")=='0'
    print('PASS multi-package selection/filter, isolated admission notes, diagnosis creation/privacy, stale forms and repeat migration')

    # Real staff HTTP paths use CSRF, form versions and assigned-clinician scope.
    php(101, "$hash=password_hash('SyntheticWorkflowPassword123',PASSWORD_DEFAULT);q('UPDATE staff SET password_hash=?',[$hash]);")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    server_log = (work / 'server.log').open('w')
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(root / 'public/index.php')],
                              env={**env, 'MASIHA_COOKIE_SECURE': '0'}, stdout=subprocess.DEVNULL, stderr=server_log)
    def token(client, path):
        page = client.get(base + path, timeout=5)
        assert page.status_code == 200, (path, page.status_code)
        return re.search(r'name="csrf" value="([^"]+)"', page.text)[1]
    def login(name):
        client = Client()
        csrf = token(client, '/login')
        page = client.post(base + '/login', data={'csrf': csrf, 'action': 'login', 'username': name, 'password': 'SyntheticWorkflowPassword123'}, timeout=5)
        assert page.status_code == 200 and 'workspace' in page.text
        return client
    try:
        for _ in range(30):
            try:
                Client().get(base + '/health', timeout=1)
                break
            except (urllib.error.URLError, TimeoutError):
                time.sleep(.1)
        assert Client().get(base + '/api/visit-timeline?session_id=701', timeout=5).status_code == 401
        reception = login('wf_reception')
        page = reception.get(base + '/visit?id=701')
        assert page.status_code == 200 and 'correction' not in page.text and 'گردش مراجعه' in page.text
        page = reception.get(base + '/appointments?from=2099-01-01&to=2099-01-31')
        assert page.status_code==200 and 'PRIVATE_DIAGNOSIS' not in page.text
        assert reception.get(base + '/appointment/details?id=705').status_code==200
        assert reception.get(base + '/appointment/catalogs').status_code==403
        assert reception.get(base + '/reports/timing').status_code==403
        admin=login('wf_admin')
        # Theme settings persist atomically; PHP pages contain no inline presentation.
        appearance=admin.get(base+'/appearance')
        assert appearance.status_code==200
        for name in ['theme_color','theme_hover_color','theme_background_color']:
            assert f'name="{name}"' in appearance.text
        assert 'data-theme-preview' in appearance.text and '/assets/workspace.css?v=1' in appearance.text
        assert not re.search(r'\s(?:style|onclick|onchange|oninput)=|<style\b|<script(?![^>]*\bsrc=)',appearance.text,re.I)
        colors={'theme_color':'#3459a8','theme_hover_color':'#23417e','theme_background_color':'#f1f5fc','font_id':'0','heading_font_id':'0'}
        saved=admin.post(base+'/appearance',data={'csrf':token(admin,'/appearance'),'action':'appearance',**colors})
        assert saved.status_code==200 and 'ذخیره شد' in saved.text
        theme=Client().get(base+'/theme.css')
        assert theme.status_code==200 and '--primary-hover: #23417e' in theme.text and '--bg: #f1f5fc' in theme.text
        assert '{{' not in theme.text and '--primary-ink: #ffffff' in theme.text
        for invalid in ['#000000;','bad','']:
            admin.post(base+'/appearance',data={'csrf':token(admin,'/appearance'),'action':'appearance',**colors,'theme_color':'#abcdef','theme_hover_color':invalid})
            assert '--primary: #3459a8' in Client().get(base+'/theme.css').text
        denied=reception.post(base+'/appearance',data={'csrf':token(reception,'/appointments'),'action':'appearance',**colors,'theme_color':'#ffffff'})
        assert denied.status_code==403 and '--primary: #3459a8' in Client().get(base+'/theme.css').text
        denied=admin.post(base+'/appearance',data={'csrf':'invalid','action':'appearance',**colors})
        assert denied.status_code==403
        reset={'theme_color':'#087f75','theme_hover_color':'#065f58','theme_background_color':'#f3f6fa','font_id':'0','heading_font_id':'0'}
        admin.post(base+'/appearance',data={'csrf':token(admin,'/appearance'),'action':'appearance',**reset})
        filtered=admin.get(base+'/appointments?presence=present')
        assert '<details class="advanced-filters" open>' in filtered.text
        assert 'reception-mode' in filtered.text and 'reception-toolbar' in filtered.text
        assert 'name="room"' in filtered.text and 'form="reception-filters"' in filtered.text
        assert filtered.text.index('reception-summary') < filtered.text.index('reception-list-search') < filtered.text.index('reception-list"')
        assert 'روز قبل' in filtered.text and 'روز بعد' in filtered.text
        # Optional disposable, synthetic render fixtures for developer visual review.
        preview_dir=os.environ.get('MASIHA_UI_PREVIEW_DIR')
        if preview_dir:
            preview=pathlib.Path(preview_dir);preview.mkdir(parents=True,exist_ok=True)
            for route,name in [('/appearance','appearance'),('/appointments','appointments'),('/','dashboard'),('/appointment/new','booking')]:
                (preview/(name+'.html')).write_text(admin.get(base+route).text)
            (preview/'theme.css').write_text(Client().get(base+'/theme.css').text)
        print('PASS theme persistence, invalid-input rollback, permission/CSRF enforcement, external assets and expanded active filters')
        for path in ['/sms','/document-types','/sms/audience','/reports/clinical?from=2099-06-01&to=2099-06-30','/progress?pid=901']:
            assert admin.get(base+path).status_code==200,path
        finance_page=admin.get(base+'/finance')
        nonce=re.search(r'name="payment_nonce" value="([^"]+)"',finance_page.text)[1]
        payment_data={'csrf':token(admin,'/finance'),'action':'payment','episode_id':803,'amount_toman':500,'reference':'synthetic','payment_nonce':nonce}
        response=admin.post(base+'/finance',payment_data)
        assert response.status_code==200
        payment_id=int(sql(f'SELECT MAX(id) FROM {database}.physio_payments'))
        assert sql(f"SELECT COUNT(*) FROM {database}.billing_events WHERE entity_type='payment' AND entity_id={payment_id}")=='1'
        admin.post(base+'/finance',payment_data)
        assert sql(f"SELECT COUNT(*) FROM {database}.physio_payments WHERE episode_id=803")=='1'
        response=reception.post(base+'/finance',{'csrf':token(reception,'/finance'),'action':'payment_edit','id':payment_id,'amount_toman':450,'reason':'denied'})
        assert response.status_code==403
        response=admin.post(base+'/finance',{'csrf':token(admin,'/finance'),'action':'payment_edit','id':payment_id,'amount_toman':450,'reason':'synthetic correction'})
        assert response.status_code==200
        response=admin.post(base+'/finance',{'csrf':token(admin,'/finance'),'action':'payment_void','id':payment_id,'reason':'synthetic void'})
        assert response.status_code==200
        assert sql(f"SELECT COUNT(*) FROM {database}.billing_events WHERE entity_type='payment' AND entity_id={payment_id}")=='3'
        assert sql(f'SELECT voided FROM {database}.physio_payments WHERE id={payment_id}')=='1'
        invoice_page=admin.get(base+'/billing?episode=803')
        assert invoice_page.status_code==200 and 'تاریخچه صورتحساب' in invoice_page.text
        history_page=admin.get(base+'/visit-history?pid=901')
        assert history_page.status_code==200 and 'مجموع قطعی' in history_page.text
        assert reception.get(base+'/visit-history?pid=901').status_code==200
        timing_page=admin.get(base+'/reports/timing?from=2099-03-01&to=2099-03-01')
        assert timing_page.status_code==200 and 'زمان معلوم' in timing_page.text
        csrf = token(reception, '/appointments')
        data = {'csrf': csrf, 'action': 'visit_state', 'session_id': 704, 'state': 'waiting', 'workflow_version': 0, 'clinic_id': 0}
        response = reception.post(base + '/appointments', data={**data, 'csrf': 'invalid'}, timeout=5)
        assert response.status_code == 403
        response = reception.post(base + '/appointments', data=data, timeout=5)
        assert response.status_code == 200
        response = reception.post(base + '/appointments', data=data, timeout=5)
        assert 'صفحه را تازه کنید' in response.text
        timeline = reception.get(base + '/api/visit-timeline?session_id=701', timeout=5).json()
        assert 'after_result' not in timeline['events'][-1]['details']
        slots_response=reception.get(base+'/api/appointment-slots?pid=901&therapist=102&clinic=1&from=2099-03-01&duration=30&room=New%20room')
        assert slots_response.status_code==200 and slots_response.json()['slots'][0]['starts_at']=='2099-03-01 09:30:00'
        next_page=reception.get(base+'/appointment/new?pid=901&previous=705')
        assert next_page.status_code==200 and 'data-find-slots' in next_page.text and 'ثبت سریع' in next_page.text
        display_csrf=token(reception,'/display')
        response=reception.post(base+'/display',data={'csrf':display_csrf,'action':'display_save','policy':'1','role':'admin'})
        assert 'فقط برای مدیر' in response.text
        assert sql(f"SELECT COUNT(*) FROM {database}.settings WHERE `key`='display.policy.admin'")=='0'
        response=reception.post(base+'/display',data={'csrf':display_csrf,'action':'display_save','policy':'0','role':'reception','display[columns][time][visible]':'on','display[columns][time][order]':'1'})
        page=reception.get(base+'/appointments?from=2099-01-01&to=2099-01-31')
        assert '<th data-column="time">' in page.text and '<th data-column="patient">' not in page.text
        assert 'href="/display"' in page.text
        reception.post(base+'/display',data={'csrf':display_csrf,'action':'display_save','policy':'0','role':'reception','reset':'1'})
        booking_csrf=token(reception,'/appointment/new')
        response=reception.post(base+'/appointment/new',data={'csrf':booking_csrf,'action':'book_v2','pid':901,'therapist_id':102,'visit_type_id':1,'date':'2099-02-01','time':'10:00','duration':30,'clinic_id':1,'label_id':0,'service_id':0,'tariff_id':0,'episode_id':0,'room':'','equipment':'','notes':''})
        assert response.status_code==200 and '/appointments?date=2099-02-01' in response.url
        assert sql(f"SELECT visit_type_id FROM {database}.physio_sessions WHERE starts_at='2099-02-01 10:00:00'")=='1'
        clinician = login('wf_therapist')
        row_page=clinician.get(base+'/appointments?from=2099-01-01&to=2099-01-31')
        assert row_page.status_code==200 and 'class="settlement-link"' not in row_page.text
        admin_rows=admin.get(base+'/appointments?from=2099-01-01&to=2099-01-31')
        assert 'class="settlement-link"' in admin_rows.text and 'data-choice-search' in admin_rows.text
        assert 'editor-packages' in admin_rows.text and 'name="selection[]"' in admin_rows.text
        assert 'name="new_diagnosis"' in admin_rows.text and 'name="notes"' in admin_rows.text
        current=int(sql(f"SELECT version FROM {database}.visit_workflows WHERE session_id=705"))
        payload={'csrf':token(reception,'/appointments'),'action':'reception_update','session_id':705,'workflow_version':current,'kind':'notes','notes':'Edited reception note'}
        response=reception.post(base+'/appointments?from=2099-01-01&to=2099-01-31',data=payload)
        assert response.status_code==200 and 'from=2099-01-01' in response.url
        assert sql(f"SELECT reception_notes FROM {database}.physio_sessions WHERE id=705")=='Edited reception note'
        visible=reception.get(base+'/appointments?from=2099-01-01&to=2099-01-31').text
        assert '<div class="reception-admission-note">Edited reception note</div>' in visible
        # Saved values must sit outside collapsed details, not only in tooltips/forms.
        from html.parser import HTMLParser
        class VisibleValues(HTMLParser):
            def __init__(self):
                super().__init__();self.depth=0;self.values=[]
            def handle_starttag(self,tag,attrs):
                if tag=='details':self.depth+=1
            def handle_endtag(self,tag):
                if tag=='details':self.depth-=1
            def handle_data(self,data):
                if self.depth==0:self.values.append(data)
        parsed=VisibleValues();parsed.feed(visible)
        assert 'Second package' in ''.join(parsed.values) and 'Edited reception note' in ''.join(parsed.values)

        response=reception.post(base+'/appointments',data={**payload,'csrf':'invalid','notes':'must not save'})
        assert response.status_code==403
        assert sql(f"SELECT reception_notes FROM {database}.physio_sessions WHERE id=705")=='Edited reception note'

        # Round-trip labels through real forms, patient profile/filter and readable history.
        current=int(sql(f"SELECT version FROM {database}.visit_workflows WHERE session_id=705"))
        tag_payload={'csrf':token(reception,'/appointments'),'action':'reception_update','session_id':705,'workflow_version':current,'kind':'labels','selection[]':'1'}
        saved=reception.post(base+'/appointments?from=2099-01-01&to=2099-01-31',data=tag_payload)
        assert saved.status_code==200 and '#Label' in saved.text
        assert sql(f"SELECT label_id FROM {database}.session_labels WHERE session_id=705")=='1'
        pid=sql(f"SELECT e.pid FROM {database}.physio_sessions s JOIN {database}.physio_episodes e ON e.id=s.episode_id WHERE s.id=705")
        assert '#Label' in admin.get(base+'/patient?id='+pid).text
        filtered=admin.get(base+'/patients?label=1&q='+pid)
        assert filtered.status_code==200 and '/patient?id='+pid in filtered.text
        history=admin.get(base+'/visit?id=705')
        assert history.status_code==200 and 'افزودن هشتگ' in history.text and 'Label' in history.text
        assert 'Edited reception note' in history.text and 'Second package' in history.text
        # Re-saving identical selections makes no new event/version.
        current+=1
        reception.post(base+'/appointments',data={**tag_payload,'workflow_version':current})
        assert int(sql(f"SELECT version FROM {database}.visit_workflows WHERE session_id=705"))==current
        # Snapshot titles survive catalog rename, including old ID-only events.
        sql(f"UPDATE {database}.labels SET name='Renamed label' WHERE id=1")
        history=admin.get(base+'/visit?id=705').text
        assert 'Label' in history
        private=reception.get(base+'/api/visit-timeline?session_id=705').json()
        assert all('after_diagnoses_names' not in ev['details'] for ev in private['events'])
        reception.post(base+'/appointments',data={k:v for k,v in {**tag_payload,'workflow_version':current}.items() if k!='selection[]'})
        assert sql(f"SELECT COUNT(*) FROM {database}.session_labels WHERE session_id=705")=='0'
        assert 'حذف هشتگ' in admin.get(base+'/visit?id=705').text
        print('PASS label HTTP round-trip, patient visibility/filter, descriptive history, no-op and clinical name privacy')

        assert clinician.get(base+'/billing?episode=803').status_code==403
        csrf = token(clinician, '/session?id=704')
        response = clinician.post(base + '/session?id=704', data={'csrf': csrf, 'action': 'session', 'session_id': 704, 'status': 'done',
                                  'pain_before': 6, 'pain_after': 2, 'rom': '115', 'notes': 'HTTP clinical result', 'workflow_version': 1}, timeout=5)
        assert response.status_code == 200 and '/session?id=704' in response.url and 'HTTP clinical result' in response.text
        assert sql(f"SELECT status FROM {database}.physio_sessions WHERE id=704") == 'done'
        other = login('wf_other')
        assert other.get(base + '/api/visit-timeline?session_id=701', timeout=5).status_code == 403
        assert other.get(base + '/visit?id=701').status_code == 403
        assert other.get(base+'/visit-history?pid=901').status_code==403
        print('PASS HTTP authentication, CSRF, stale forms, timeline privacy and assigned-clinician result path')
    finally:
        server.terminate();server.wait(timeout=5);server_log.close()
        if sys.exc_info()[0]:
            print((work / 'server.log').read_text()[-3000:])
finally:
    sql('DROP DATABASE IF EXISTS ' + database)
    shutil.rmtree(work)
    print('Removed isolated workflow database and configuration')
