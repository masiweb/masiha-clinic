"""Workflow regression tests against a disposable MariaDB, never the clinic DB."""
import json, os, pathlib, re, secrets, shutil, socket, subprocess, tempfile, time
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
    for name in ['schema.sql', 'therapy.sql', 'admin-v2.sql', 'import.sql']:
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

    # Real staff HTTP paths use CSRF, form versions and assigned-clinician scope.
    php(101, "$hash=password_hash('SyntheticWorkflowPassword123',PASSWORD_DEFAULT);q('UPDATE staff SET password_hash=?',[$hash]);")
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(root / 'public/index.php')],
                              env={**env, 'MASIHA_COOKIE_SECURE': '0'}, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
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
        clinician = login('wf_therapist')
        csrf = token(clinician, '/session?id=704')
        response = clinician.post(base + '/session?id=704', data={'csrf': csrf, 'action': 'session', 'session_id': 704, 'status': 'done',
                                  'pain_before': 6, 'pain_after': 2, 'rom': '115', 'notes': 'HTTP clinical result', 'workflow_version': 1}, timeout=5)
        assert response.status_code == 200 and '/session?id=704' in response.url and 'HTTP clinical result' in response.text
        assert sql(f"SELECT status FROM {database}.physio_sessions WHERE id=704") == 'done'
        other = login('wf_other')
        assert other.get(base + '/api/visit-timeline?session_id=701', timeout=5).status_code == 403
        print('PASS HTTP authentication, CSRF, stale forms, timeline privacy and assigned-clinician result path')
    finally:
        server.terminate();server.wait(timeout=5)
finally:
    sql('DROP DATABASE IF EXISTS ' + database)
    shutil.rmtree(work)
    print('Removed isolated workflow database and configuration')
