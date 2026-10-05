"""Exercise upgrade success/failure against disposable files and simulated services."""
import json, os, pathlib, shutil, subprocess, tempfile

root = pathlib.Path(__file__).resolve().parent.parent

def case(name, php_active=True, importer_timer=True, sms_timer=False, failure='', worker=False):
    with tempfile.TemporaryDirectory(prefix='clinic-upgrade-test-') as directory:
        work = pathlib.Path(directory);source = work / 'source';target = work / 'target';bin = work / 'bin'
        source.mkdir();target.mkdir();bin.mkdir()
        for item in ['app', 'public', 'deploy', 'importer']:
            (source / item).mkdir();(target / item).mkdir()
            (source / item / 'marker.php').write_text('<?php // new')
            (target / item / 'marker.php').write_text('<?php // old')
        shutil.copyfile(root / 'deploy/upgrade.sh', source / 'deploy/upgrade.sh')
        for item in ['admin-v2.sql', 'import.sql', 'workflow.sql', 'appointments.sql']:
            (source / 'deploy' / item).write_text('-- isolated migration')
        for args in [['init','-q'], ['add','.'], ['-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm','Synthetic source']]:
            subprocess.run(['git','-C',str(source),*args], check=True)
        config = work / 'config.php';config.write_text('<?php return [];')
        state = work / 'state.json';log = work / 'operations.jsonl'
        initial = {'php8.3-fpm':php_active,'masiha-importer.timer':importer_timer,
                   'masiha-sms-worker.timer':sms_timer,'masiha-importer.service':worker,'masiha-sms-worker.service':False}
        state.write_text(json.dumps(initial))
        runner = '''#!/usr/bin/python3
import json,os,pathlib,sys
state=pathlib.Path(os.environ['TEST_STATE']);log=pathlib.Path(os.environ['TEST_LOG'])
args=sys.argv[1:];tool=pathlib.Path(sys.argv[0]).name
with log.open('a') as out:out.write(json.dumps([tool,*args])+'\\n')
data=json.loads(state.read_text())
failure=os.environ.get('TEST_FAILURE','')
if tool=='systemctl':
 action=args[0];unit=args[-1]
 if action=='show':
  value=data.get(unit,False);print(value if isinstance(value,str) else ('active' if value else 'inactive'));sys.exit(0)
 if action=='is-active':sys.exit(0 if data.get(unit,False) else 3)
 if action in ['start','stop']:
  data[unit]=action=='start'
  if failure.startswith('race') and action=='stop' and unit=='masiha-importer.timer':data['masiha-importer.service']='activating' if failure=='race-activating' else True
  state.write_text(json.dumps(data));sys.exit(0)
 raise SystemExit('Unexpected service mutation')
if tool=='mariadb':
 sys.stdin.read();sys.exit(7 if failure=='migration' else 0)
if tool=='backup':sys.exit(8 if failure=='backup' else 0)
if tool=='php':sys.exit(0)
if tool=='cp':
 if failure=='copy' and '/public/' in args[-1]:sys.exit(9)
 os.execv('/bin/cp',['cp',*args])
'''
        for tool in ['systemctl','mariadb','backup','php','cp']:
            file = bin / tool;file.write_text(runner);file.chmod(0o755)
        env = {**os.environ,'PATH':str(bin)+':'+os.environ['PATH'],'MASIHA_CONFIG':str(config),
               'MASIHA_DEPLOY_ROOT':str(target),'MASIHA_BACKUP_DIR':str(work / 'backups'),
               'MASIHA_BACKUP_COMMAND':str(bin / 'backup'),'TEST_STATE':str(state),'TEST_LOG':str(log),'TEST_FAILURE':failure}
        process = subprocess.run(['bash',str(source / 'deploy/upgrade.sh')], env=env, capture_output=True,text=True)
        final = json.loads(state.read_text());actions = [json.loads(line) for line in log.read_text().splitlines()]
        if failure or worker:
            assert process.returncode != 0, (name,process.stdout,process.stderr)
            assert (target / 'app/marker.php').read_text() == '<?php // old',name
        else:
            assert process.returncode == 0, (name,process.stdout,process.stderr)
            assert (target / 'app/marker.php').read_text() == '<?php // new',name
            assert 'Code snapshot:' in process.stdout
        for unit in ['php8.3-fpm','masiha-importer.timer','masiha-sms-worker.timer']:
            assert final[unit] == initial[unit], (name,unit,initial,final)
        assert not any(a[0]=='systemctl' and a[1] in ['enable','disable','restart'] for a in actions),name
        if worker:assert not any(a[0]=='backup' or (a[0]=='systemctl' and a[1]=='stop') for a in actions)
        print('PASS',name)

if os.geteuid() != 0:
    raise SystemExit('Run this isolated deployment simulation as root, matching CI')
case('success retains inactive SMS and active importer')
case('success retains active SMS and inactive importer',importer_timer=False,sms_timer=True)
case('success retains initially stopped PHP',php_active=False,importer_timer=False)
case('backup failure restores service states',failure='backup')
case('migration failure retains old code and service states',failure='migration')
case('partial copy rolls code back before restoring services',failure='copy')
case('active worker refuses deployment before mutation',worker=True)
case('worker starting during timer pause refuses deployment',failure='race')

for state in ['activating','deactivating','reloading']:
    case(state+' worker refuses deployment before mutation',worker=state)
case('oneshot starting during timer pause refuses deployment',failure='race-activating')
