#!/usr/bin/env python3
"""Export the installed clinic faithfully; publish code only, never private data."""
import argparse, base64, datetime, gzip, hashlib, json, os, pathlib, re, shutil
import subprocess, tarfile, urllib.error, urllib.request

SCHEMA_FILES = {'admin-v2.sql','import.sql','otp.sql','portal.sql','schema.sql','therapy.sql','workflow.sql'}
SKIP_DIRS = {'.git','__pycache__','.venv','venv','node_modules'}
TIMERS = ['masiha-importer.timer','masiha-sms-worker.timer']
WORKERS = ['masiha-importer.service','masiha-sms-worker.service']

def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)

def active(unit):
    return subprocess.run(['systemctl','is-active','--quiet',unit], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0

def digest(path):
    result = hashlib.sha256()
    with path.open('rb') as file:
        for chunk in iter(lambda:file.read(1024*1024),b''):result.update(chunk)
    return result.hexdigest()

def code_files(root):
    files = []
    for folder in ['app','public','deploy','importer','tests','docs']:
        for directory, dirs, names in os.walk(root / folder):
            dirs[:] = [name for name in dirs if name not in SKIP_DIRS and name not in {'uploads','backups','profiles'}]
            for name in names:
                file = pathlib.Path(directory) / name
                if file.is_symlink() or name.startswith('.') or '.bak' in name:continue
                suffix = file.suffix.lower()
                if name in {'config.php','install.json','sms.json','identity.json'}:continue
                if suffix == '.sql' and (folder != 'deploy' or name not in SCHEMA_FILES):continue
                if suffix not in {'.php','.py','.sh','.js','.css','.sql','.md','.woff','.woff2','.ttf','.svg','.png','.jpg','.jpeg','.gif','.webp','.ico'} and name not in {'requirements.txt','LICENSE','OFL.txt'}:continue
                files.append(file)
    for name in ['setup.sh','README.md','LICENSE','THIRD_PARTY.md']:
        file = root / name
        if file.is_file() and not file.is_symlink():files.append(file)
    return sorted(set(files))

def public_code_issue(file):
    if file.suffix in {'.woff','.woff2','.ttf','.png','.jpg','.jpeg','.gif','.webp','.ico'}:return None
    text = file.read_text(errors='replace')
    patterns = [r'-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9]{20,}',
                r'github_pat_[A-Za-z0-9_]{20,}',r'AKIA[A-Z0-9]{16}',
                r'(?i)(?:password|api_key|token|secret)\s*[=:]\s*[\x22\x27][A-Za-z0-9+/_=-]{32,}[\x22\x27]']
    return 'possible_secret' if any(re.search(pattern,text) for pattern in patterns) else None

def code_archive(root, output):
    files = code_files(root)
    if not files:raise RuntimeError('No deployed code found')
    manifest = [{'path':str(file.relative_to(root)), 'size':file.stat().st_size, 'sha256':digest(file)} for file in files]
    archive = output / 'deployed-code.tar.gz'
    with tarfile.open(archive,'w:gz') as tar:
        for file in files:tar.add(file,arcname=str(file.relative_to(root)),recursive=False)
    if any(digest(root / item['path']) != item['sha256'] for item in manifest):
        raise RuntimeError('Code changed during export; retry from a stable checkout')
    with tarfile.open(archive) as tar:
        for item in manifest:
            with tar.extractfile(item['path']) as file:
                if hashlib.sha256(file.read()).hexdigest() != item['sha256']:
                    raise RuntimeError('Archived code does not match the manifest')
    (output / 'code-manifest.json').write_text(json.dumps({'files':manifest},ensure_ascii=False,indent=2))
    return files, manifest

def private_archive(archive, paths):
    # Browser locks/sockets and regenerable caches are not portable records.
    def filter_member(member):
        parts = pathlib.PurePosixPath(member.name).parts
        if any(part in SKIP_DIRS or part in {'chrome-cache','Cache','Code Cache','GPUCache'} or part.startswith('Singleton') for part in parts):return None
        if not (member.isfile() or member.isdir()):return None
        return member
    with tarfile.open(archive,'w:gz',dereference=False) as tar:
        for source, name in paths:tar.add(source,arcname=name,filter=filter_member)

def deployed_services(output):
    paths = []
    for name in ['masiha-importer.service','masiha-importer.timer','masiha-sms-worker.service','masiha-sms-worker.timer']:
        path = pathlib.Path('/etc/systemd/system') / name
        if path.is_file():paths.append((str(path),str(path).lstrip('/')))
    for name in ['/etc/nginx/sites-available/masiha-clinic','/etc/php/8.3/fpm/conf.d/99-masiha.ini',
                 '/etc/cron.d/masiha-clinic','/usr/local/sbin/masiha-backup']:
        if pathlib.Path(name).is_file():paths.append((name,name.lstrip('/')))
    private_archive(output / 'service-reference.tar.gz', paths)
    # Reference only: destination networking/TLS/agent identity must be fresh.
    with (output / 'packages.txt').open('w') as file:
        run(['dpkg-query','-W','-f=${Package}\t${Version}\n'],stdout=file)

def database_counts(database):
    tables = subprocess.check_output(['mariadb','-N',database,'-e','SHOW TABLES'],text=True).splitlines()
    if any(not re.fullmatch(r'[A-Za-z0-9_]+',table) for table in tables):raise RuntimeError('Unexpected table name')
    return {table:int(subprocess.check_output(['mariadb','-N',database,'-e',f'SELECT COUNT(*) FROM `{table}`'],text=True)) for table in tables}

def export(root, output, private, system_root=pathlib.Path('/')):
    if output.exists():raise RuntimeError('Output directory already exists; choose a new name')
    output.mkdir(parents=True,mode=0o700)
    paused = [];php_was_active = False;php_paused = False
    service_state = {}
    try:
        if private:
            if os.geteuid() != 0:raise RuntimeError('Private export requires root')
            if any(active(unit) for unit in WORKERS):raise RuntimeError('A worker is active; wait for it to finish and retry')
            service_state = {unit:active(unit) for unit in TIMERS + ['php8.3-fpm']}
            for unit in TIMERS:
                if service_state[unit]:run(['systemctl','stop',unit]);paused.append(unit)
            if any(active(unit) for unit in WORKERS):raise RuntimeError('A worker started during preflight; retry')
            php_was_active = service_state['php8.3-fpm']
            if php_was_active:run(['systemctl','stop','php8.3-fpm']);php_paused = True
        files, manifest = code_archive(root,output)
        result = {'format':'masiha-transfer-v1','created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                  'hostname':os.uname().nodename,'code_root':str(root),'code_files':len(files),
                  'code_sha256':digest(output / 'deployed-code.tar.gz'),'service_state':service_state}
        if private:
            for path in ['etc/masiha-clinic','var/lib/masiha-clinic']:
                if not (system_root / path).is_dir():raise RuntimeError('Missing private application directory: '+path)
            counts = database_counts('masiha_clinic')
            dump = output / 'database.sql.gz'
            with gzip.open(dump,'wb') as dest, (output / 'dump-error.log').open('wb') as errors:
                process = subprocess.Popen(['mariadb-dump','--single-transaction','--routines','--triggers','--events','masiha_clinic'],stdout=subprocess.PIPE,stderr=errors)
                shutil.copyfileobj(process.stdout,dest)
                process.stdout.close();status = process.wait()
                if status:raise RuntimeError('Database dump failed (private details kept on source)')
            if counts != database_counts('masiha_clinic'):raise RuntimeError('Database changed during backup; retry with all writers stopped')
            private_archive(output / 'private.tar.gz', [(str(system_root / path),path) for path in ['etc/masiha-clinic','var/lib/masiha-clinic']])
            private_archive(output / 'application-private.tar.gz', [(str(root),'masiha-clinic')])
            deployed_services(output)
            result['table_counts'] = counts
            result['archives'] = {name:digest(output / name) for name in ['deployed-code.tar.gz','database.sql.gz','private.tar.gz','application-private.tar.gz','service-reference.tar.gz']}
            # No SentinelX identity, SSH keys, TLS keys or operating system credentials.
            (output / 'transfer-manifest.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
            with tarfile.open(output / 'clinic-transfer.tar.gz','w:gz') as tar:
                for name in ['deployed-code.tar.gz','code-manifest.json','database.sql.gz','private.tar.gz','application-private.tar.gz','service-reference.tar.gz','packages.txt','transfer-manifest.json']:
                    tar.add(output / name,arcname=name,recursive=False)
            checksum = digest(output / 'clinic-transfer.tar.gz')
            (output / 'clinic-transfer.tar.gz.sha256').write_text(checksum+'  clinic-transfer.tar.gz\n')
            print('PRIVATE_BUNDLE='+str(output / 'clinic-transfer.tar.gz'),flush=True)
            print('PRIVATE_SHA256='+checksum,flush=True)
        else:
            (output / 'transfer-manifest.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
        print('CODE_ARCHIVE='+str(output / 'deployed-code.tar.gz'),flush=True)
        return files,manifest
    finally:
        recovered = True
        if php_paused:
            try:run(['systemctl','start','php8.3-fpm'])
            except subprocess.CalledProcessError:recovered = False
        if recovered:
            errors = []
            for unit in paused:
                try:run(['systemctl','start',unit])
                except subprocess.CalledProcessError:errors.append(unit)
            if errors:raise RuntimeError('Failed restoring timers: '+', '.join(errors))
        elif paused:print('PHP recovery failed; original active timers remain paused',flush=True)
        if not recovered:raise RuntimeError('PHP recovery failed; check the source server immediately')

def publish(root, output, repository, branch, base_ref):
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+',repository):raise RuntimeError('Invalid repository')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_/-]*',branch) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_/-]*',base_ref):raise RuntimeError('Invalid branch')
    manifest = json.loads((output / 'code-manifest.json').read_text())
    eligible = {str(file.relative_to(root)) for file in code_files(root)}
    if any(item['path'] not in eligible for item in manifest['files']):raise RuntimeError('Code manifest includes an ineligible path')
    files = [root / item['path'] for item in manifest['files']]
    flagged = [str(file.relative_to(root)) for file in files if public_code_issue(file)]
    if flagged:raise RuntimeError('Public code upload refused: potential secrets in '+', '.join(flagged))
    if any(digest(root / item['path']) != item['sha256'] for item in manifest['files']):raise RuntimeError('Code changed since backup')
    token = os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN')
    if not token:
        token = subprocess.check_output(['gh','auth','token','--hostname','github.com'],stderr=subprocess.DEVNULL,text=True).strip()
    def api(method, path, payload=None):
        request = urllib.request.Request('https://api.github.com'+path,method=method,
            headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json','Content-Type':'application/json','User-Agent':'masiha-transfer'},
            data=json.dumps(payload).encode() if payload is not None else None)
        try:
            with urllib.request.urlopen(request,timeout=30) as response:return json.load(response)
        except urllib.error.HTTPError as error:raise RuntimeError('GitHub returned HTTP '+str(error.code)) from None
    prefix = '/repos/'+repository
    repo = api('GET',prefix)
    if not repo.get('permissions',{}).get('push'):raise RuntimeError('Token cannot push to this repository')
    ref = api('GET',prefix+'/git/ref/heads/'+base_ref)
    parent = ref['object']['sha'];tree = api('GET',prefix+'/git/commits/'+parent)['tree']['sha']
    entries = [];folder = 'server-snapshots/'+output.name+'/code/'
    for file in files:
        blob = api('POST',prefix+'/git/blobs',{'encoding':'base64','content':base64.b64encode(file.read_bytes()).decode()})
        entries.append({'path':folder+str(file.relative_to(root)),'mode':'100644','type':'blob','sha':blob['sha']})
    entries.append({'path':'server-snapshots/'+output.name+'/code-manifest.json','mode':'100644','type':'blob','content':json.dumps(manifest,ensure_ascii=False,indent=2)})
    tree = api('POST',prefix+'/git/trees',{'base_tree':tree,'tree':entries})['sha']
    commit = api('POST',prefix+'/git/commits',{'message':'Archive actual deployed clinic code '+output.name,'tree':tree,'parents':[parent]})['sha']
    api('POST',prefix+'/git/refs',{'ref':'refs/heads/'+branch,'sha':commit})
    print('GITHUB_COMMIT='+commit,flush=True)
    print('GITHUB_BRANCH='+branch,flush=True)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--app-root',default='/var/www/masiha-clinic')
    parser.add_argument('--output',required=True)
    parser.add_argument('--with-private',action='store_true')
    parser.add_argument('--publish',metavar='OWNER/REPO')
    parser.add_argument('--branch')
    parser.add_argument('--base-ref',default='main')
    parser.add_argument('--publish-existing',action='store_true',help='Publish a previously exported and manually reviewed code manifest')
    args = parser.parse_args()
    if args.publish and not args.branch:parser.error('--publish requires a new --branch')
    root = pathlib.Path(args.app_root).resolve();output = pathlib.Path(args.output).resolve()
    if output == root or root in output.parents:raise RuntimeError('Store exports outside the application directory')
    os.umask(0o077)
    if args.publish and not args.publish_existing:parser.error('Export first, review code, then use --publish-existing')
    if args.publish_existing and not args.publish:parser.error('--publish-existing requires --publish')
    if not args.publish_existing:export(root,output,args.with_private)
    if args.publish:publish(root,output,args.publish,args.branch,args.base_ref)

if __name__ == '__main__':
    try:main()
    except Exception as error:
        print('EXPORT_FAILED: '+str(error),flush=True)
        raise SystemExit(1)
