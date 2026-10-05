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
        csrf = token(clinician, '/session?id=704')
        response = clinician.post(base + '/session?id=704', data={'csrf': csrf, 'action': 'session', 'session_id': 704, 'status': 'done',
                                  'pain_before': 6, 'pain_after': 2, 'rom': '115', 'notes': 'HTTP clinical result', 'workflow_version': 1}, timeout=5)
        assert response.status_code == 200 and '/session?id=704' in response.url and 'HTTP clinical result' in response.text
        assert sql(f"SELECT status FROM {database}.physio_sessions WHERE id=704") == 'done'
        other = login('wf_other')
        assert other.get(base + '/api/visit-timeline?session_id=701', timeout=5).status_code == 403
        assert other.get(base + '/visit?id=701').status_code == 403
        print('PASS HTTP authentication, CSRF, stale forms, timeline privacy and assigned-clinician result path')
    finally:
        server.terminate();server.wait(timeout=5);server_log.close()
        if sys.exc_info()[0]:
            print((work / 'server.log').read_text()[-3000:])
finally:
    sql('DROP DATABASE IF EXISTS ' + database)
    shutil.rmtree(work)
    print('Removed isolated workflow database and configuration')
