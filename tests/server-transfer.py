"""Exercise real archives/streaming with disposable files and simulated services."""
import importlib.util, io, json, os, pathlib, subprocess, tarfile, tempfile
from unittest.mock import patch

ROOT = pathlib.Path(__file__).resolve().parent.parent
def module(name, path):
    spec = importlib.util.spec_from_file_location(name,path)
    result = importlib.util.module_from_spec(spec);spec.loader.exec_module(result);return result
export = module('transfer_export',ROOT / 'deploy/export-server.py')
restore = module('transfer_restore',ROOT / 'deploy/restore-server.py')

RUNNER = '''#!/usr/bin/python3
import json,os,pathlib,sys
args=sys.argv[1:];tool=pathlib.Path(sys.argv[0]).name
state=pathlib.Path(os.environ['TRANSFER_TEST_STATE']);data=json.loads(state.read_text())
failure=os.environ.get('TRANSFER_TEST_FAILURE','')
with pathlib.Path(os.environ['TRANSFER_TEST_LOG']).open('a') as log:log.write(json.dumps([tool,*args])+'\\n')
if tool=='systemctl':
 action=args[0];unit=args[-1]
 if action=='is-active':sys.exit(0 if data.get(unit,False) else 3)
 if action in ['start','stop','disable']:
  if action=='start' and ((failure=='php-start' and unit=='php8.3-fpm') or (failure=='timer-start' and unit=='masiha-importer.timer')):sys.exit(7)
  data[unit]=action=='start'
  if failure=='race' and action=='stop' and unit=='masiha-importer.timer':data['masiha-importer.service']=True
  state.write_text(json.dumps(data));sys.exit(0)
 raise SystemExit('Unexpected service action')
if tool=='mariadb':
 if '-N' in args:
  if args[-1]=='SHOW TABLES':print('patients');sys.exit(0)
  print(3 if failure=='restore-count' and data.get('imported') else 2);sys.exit(0)
 payload=sys.stdin.read()
 if 'DROP DATABASE' in payload:pathlib.Path(os.environ['TRANSFER_TEST_SQL']).write_text(payload)
 else:data['imported']=True;state.write_text(json.dumps(data))
 sys.exit(9 if failure=='import' and data.get('imported') else 0)
if tool=='mariadb-dump':
 if failure=='dump':sys.stderr.write('private details');sys.exit(8)
 print('CREATE TABLE patients(id INT); INSERT INTO patients VALUES (1),(2);');sys.exit(0)
if tool=='php':print(json.dumps({'dsn':'mysql:host=localhost;dbname=masiha_clinic;charset=utf8mb4','user':'masiha_clinic','storage':'/var/lib/masiha-clinic','password':"synthetic'password\\\\test"}));sys.exit(0)
if tool=='chown':sys.exit(0)
if tool=='curl':sys.exit(10 if failure=='health' else 0)
raise SystemExit('Unexpected test command')
'''

def fixture(work):
    app = work / 'source';system = work / 'source-system';binpath = work / 'bin';binpath.mkdir()
    for name in ['app/bootstrap.php','public/index.php','importer/worker.py','importer/requirements.txt','deploy/schema.sql','deploy/schema-dump.sql','unknown.runtime','app/config.php']:
        file = app / name;file.parent.mkdir(parents=True,exist_ok=True);file.write_text('synthetic deployed '+name)
    (app / 'public/assets').mkdir();(app / 'public/assets/font.woff2').write_bytes(b'font fixture')
    (app / 'app/link.php').symlink_to(app / 'app/bootstrap.php')
    config = system / 'etc/masiha-clinic/config.php';config.parent.mkdir(parents=True);config.write_text('<?php return [];')
    patient = system / 'var/lib/masiha-clinic/documents/private.txt';patient.parent.mkdir(parents=True);patient.write_text('synthetic private document')
    cache = system / 'var/lib/masiha-clinic/importer/chrome-cache/cache';cache.parent.mkdir(parents=True);cache.write_text('regenerable')
    for tool in ['systemctl','mariadb','mariadb-dump','php','chown','curl']:
        file = binpath / tool;file.write_text(RUNNER);file.chmod(0o755)
    state = work / 'state.json';state.write_text(json.dumps({'php8.3-fpm':True,'masiha-importer.timer':True,'masiha-sms-worker.timer':True}))
    env = {**os.environ,'PATH':str(binpath)+':'+os.environ['PATH'],'TRANSFER_TEST_STATE':str(state),
           'TRANSFER_TEST_LOG':str(work / 'log.jsonl'),'TRANSFER_TEST_SQL':str(work / 'sql.txt')}
    return app,system,state,env

def references(output):
    with tarfile.open(output / 'service-reference.tar.gz','w:gz'):pass
    (output / 'packages.txt').write_text('synthetic-package\t1\n')

def rejected(operation):
    try:operation()
    except (RuntimeError,subprocess.CalledProcessError):return
    raise AssertionError('Expected operation to be refused')

def export_case(failure=''):
    with tempfile.TemporaryDirectory() as directory:
        work = pathlib.Path(directory);app,system,state,env = fixture(work)
        env['TRANSFER_TEST_FAILURE'] = failure
        if failure=='worker':
            data = json.loads(state.read_text());data['masiha-importer.service']=True;state.write_text(json.dumps(data))
        with patch.dict(os.environ,env,clear=True),patch.object(export.os,'geteuid',return_value=0),patch.object(export,'deployed_services',references):
            operation = lambda:export.export(app,work / 'output',True,system)
            if failure:rejected(operation)
            else:
                operation();staging = work / 'staging';staging.mkdir()
                manifest = restore.prepare(work / 'output/clinic-transfer.tar.gz',staging)
                assert manifest['table_counts']=={'patients':2}
                paths = {item['path'] for item in json.loads((work / 'output/code-manifest.json').read_text())['files']}
                assert 'app/config.php' not in paths and 'app/link.php' not in paths and 'deploy/schema-dump.sql' not in paths
                assert 'public/assets/font.woff2' in paths
                assert (staging / 'application/masiha-clinic/unknown.runtime').is_file()
                assert (staging / 'application/masiha-clinic/app/config.php').is_file()
                assert (staging / 'private/var/lib/masiha-clinic/documents/private.txt').is_file()
                assert not (staging / 'private/var/lib/masiha-clinic/importer/chrome-cache').exists()
        final = json.loads(state.read_text())
        assert final['php8.3-fpm'] == (failure != 'php-start')
        assert final['masiha-importer.timer'] == (failure not in ['php-start','timer-start'])
        assert final['masiha-sms-worker.timer'] == (failure != 'php-start')
        if failure=='worker':assert not (work / 'output/deployed-code.tar.gz').exists()
        print('PASS export',failure or 'complete snapshot and archive verification')

def restore_case(failure=''):
    with tempfile.TemporaryDirectory() as directory:
        work = pathlib.Path(directory);app,system,state,env = fixture(work)
        with patch.dict(os.environ,env,clear=True),patch.object(export.os,'geteuid',return_value=0),patch.object(export,'deployed_services',references):
            export.export(app,work / 'output',True,system)
            staging = work / 'staging';staging.mkdir();manifest = restore.prepare(work / 'output/clinic-transfer.tar.gz',staging)
            dest = work / 'destination';marker = dest / 'var/lib/masiha-migration-pending';marker.parent.mkdir(parents=True)
            if failure != 'existing':marker.touch()
            env['TRANSFER_TEST_FAILURE']=failure;os.environ['TRANSFER_TEST_FAILURE']=failure
            operation = lambda:restore.restore(staging,manifest,'clinic.example.invalid',dest)
            if failure:rejected(operation)
            else:
                operation()
                assert not marker.exists()
                assert (dest / 'var/www/masiha-clinic/unknown.runtime').is_file()
                assert (dest / 'var/lib/masiha-clinic/documents/private.txt').read_text()=='synthetic private document'
                assert json.loads((dest / 'var/lib/masiha-migration-verified.json').read_text())['table_counts']=={'patients':2}
                sql = (work / 'sql.txt').read_text()
                assert "synthetic'password" not in sql and 'SET @p=CONVERT(0x' in sql
                if os.environ.get('MASIHA_TRANSFER_SQL_CHECK')=='1':
                    # Explicit opt-in: validate credential SQL on a disposable database/user.
                    sql = sql.replace('masiha_clinic','masiha_transfer_test')
                    actual = os.environ['MASIHA_TRANSFER_REAL_MARIADB']
                    subprocess.run([actual],input=sql,text=True,check=True)
                    subprocess.run([actual],input="DROP DATABASE masiha_transfer_test;DROP USER 'masiha_transfer_test'@'localhost';",text=True,check=True)
            final = json.loads(state.read_text())
            if failure != 'existing':
                assert not final['masiha-importer.timer'] and not final['masiha-sms-worker.timer']
                assert final['php8.3-fpm'] == (not failure)
            else:assert not (work / 'sql.txt').exists()
        print('PASS restore',failure or 'exact code/private files and row counts')

for failure in ['', 'worker','race','dump','php-start','timer-start']:export_case(failure)
for failure in ['', 'existing','import','restore-count','health']:restore_case(failure)
with tempfile.TemporaryDirectory() as directory:
    work = pathlib.Path(directory)
    for name,kind in [('../escape','file'),('/absolute','file'),('safe/link','link')]:
        archive = work / 'unsafe.tar.gz'
        with tarfile.open(archive,'w:gz') as tar:
            entry = tarfile.TarInfo(name)
            if kind=='link':entry.type=tarfile.SYMTYPE;entry.linkname='/etc/passwd';tar.addfile(entry)
            else:entry.size=1;tar.addfile(entry,io.BytesIO(b'x'))
        rejected(lambda:restore.extract_checked(archive,work / 'unpacked'))
    (work / 'fake.tar.gz').write_bytes(b'corrupted');(work / 'fake.tar.gz.sha256').write_text('0'*64+'  fake.tar.gz\n')
    rejected(lambda:restore.prepare(work / 'fake.tar.gz',work / 'unpacked'))
    assert not (work / 'escape').exists()
print('PASS rejects corrupt, traversal and symlink archives')
with tempfile.TemporaryDirectory() as directory:
    file = pathlib.Path(directory) / 'secret.php';file.write_text('<?php $token="ghp_'+('a'*32)+'";')
    assert export.public_code_issue(file)=='possible_secret'
print('PASS rejects obvious credentials in public code')
