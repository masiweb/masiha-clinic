#!/usr/bin/env python3
"""Restore a complete clinic transfer onto a freshly prepared destination."""
import argparse, gzip, hashlib, importlib.util, json, os, pathlib, re, shutil
import subprocess, tarfile, tempfile

def load_exporter():
    spec = importlib.util.spec_from_file_location('clinic_export',pathlib.Path(__file__).with_name('export-server.py'))
    module = importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module

exporter = load_exporter()
run = exporter.run
digest = exporter.digest

def extract_checked(archive, destination, prefixes=None, names=None):
    """Accept only ordinary files/directories inside the declared scope."""
    with tarfile.open(archive) as tar:
        members = tar.getmembers();seen = set()
        for member in members:
            path = pathlib.PurePosixPath(member.name)
            if path.is_absolute() or '..' in path.parts or not path.parts or member.name in seen:
                raise RuntimeError('Unsafe or duplicate archive path')
            seen.add(member.name)
            if not (member.isfile() or member.isdir()):raise RuntimeError('Links/devices are not accepted in transfer archives')
            if prefixes and not any(path == prefix or prefix in path.parents for prefix in map(pathlib.PurePosixPath,prefixes)):
                raise RuntimeError('Archive path is outside the clinic scope')
            if names and member.name not in names:raise RuntimeError('Unexpected transfer member')
        for member in members:
            target = destination.joinpath(*pathlib.PurePosixPath(member.name).parts)
            if target.is_symlink() or any(parent.is_symlink() for parent in target.parents):raise RuntimeError('Destination contains a symlink')
            if member.isdir():target.mkdir(parents=True,exist_ok=True);continue
            target.parent.mkdir(parents=True,exist_ok=True)
            with tar.extractfile(member) as source, target.open('wb') as dest:shutil.copyfileobj(source,dest)
            target.chmod(member.mode & 0o777)

def prepare(bundle, staging):
    checksum = pathlib.Path(str(bundle)+'.sha256')
    checksum_parts = checksum.read_text().split() if checksum.is_file() else []
    if not checksum_parts or not re.fullmatch(r'[0-9a-f]{64}',checksum_parts[0]):raise RuntimeError('Missing/invalid checksum file')
    if digest(bundle) != checksum_parts[0]:raise RuntimeError('Transfer checksum mismatch')
    names = {'deployed-code.tar.gz','code-manifest.json','database.sql.gz','private.tar.gz',
             'application-private.tar.gz','service-reference.tar.gz','packages.txt','transfer-manifest.json'}
    extract_checked(bundle,staging,names=names)
    if any(not (staging / name).is_file() for name in names):raise RuntimeError('Incomplete transfer')
    manifest = json.loads((staging / 'transfer-manifest.json').read_text())
    if manifest.get('format') != 'masiha-transfer-v1':raise RuntimeError('Unsupported transfer format')
    expected_archives = names - {'code-manifest.json','packages.txt','transfer-manifest.json'}
    if set(manifest.get('archives',{})) != expected_archives:raise RuntimeError('Incomplete archive manifest')
    for name, checksum in manifest['archives'].items():
        if digest(staging / name) != checksum:raise RuntimeError('Inner archive checksum mismatch')
    if not manifest.get('table_counts') or any(not re.fullmatch(r'[A-Za-z0-9_]+',name) or type(count) is not int or count < 0 for name,count in manifest['table_counts'].items()):
        raise RuntimeError('Invalid database row-count manifest')
    extract_checked(staging / 'application-private.tar.gz',staging / 'application',prefixes=['masiha-clinic'])
    extract_checked(staging / 'private.tar.gz',staging / 'private',prefixes=['etc/masiha-clinic','var/lib/masiha-clinic'])
    application = staging / 'application/masiha-clinic'
    private = staging / 'private'
    for file in ['app/bootstrap.php','public/index.php','importer/requirements.txt']:
        if not (application / file).is_file():raise RuntimeError('Missing required application file')
    if not (private / 'etc/masiha-clinic/config.php').is_file() or not (private / 'var/lib/masiha-clinic').is_dir():raise RuntimeError('Missing private configuration/storage')
    # Check all public code bytes against the full application snapshot too.
    for item in json.loads((staging / 'code-manifest.json').read_text())['files']:
        path = pathlib.PurePosixPath(item['path'])
        if path.is_absolute() or '..' in path.parts or not path.parts:raise RuntimeError('Unsafe code manifest')
        if digest(application.joinpath(*path.parts)) != item['sha256']:raise RuntimeError('Application/code manifest mismatch')
    return manifest

def configuration(path):
    script = '$x=require $argv[1];echo json_encode($x,JSON_THROW_ON_ERROR);'
    result = subprocess.run(['php','-r',script,str(path)],check=True,capture_output=True,text=True)
    cfg = json.loads(result.stdout)
    if cfg.get('user') != 'masiha_clinic' or cfg.get('storage') != '/var/lib/masiha-clinic':raise RuntimeError('Unsupported configuration user/storage')
    if cfg.get('dsn') != 'mysql:host=localhost;dbname=masiha_clinic;charset=utf8mb4':raise RuntimeError('Review nonstandard database DSN before migration')
    if not isinstance(cfg.get('password'),str) or not 1 <= len(cfg['password']) <= 512:raise RuntimeError('Invalid database password configuration')
    return cfg

def private_permissions(path):
    for directory,dirs,files in os.walk(path):
        pathlib.Path(directory).chmod(0o700)
        for file in files:(pathlib.Path(directory) / file).chmod(0o600)

def restore(staging, manifest, host, destination=pathlib.Path('/')):
    marker = destination / 'var/lib/masiha-migration-pending'
    if not marker.is_file():raise RuntimeError('Destination is not a fresh migration setup; existing deployments are refused')
    if any(exporter.active(unit) for unit in exporter.WORKERS):raise RuntimeError('A destination worker is active; wait for it to finish')
    cfg = configuration(staging / 'private/etc/masiha-clinic/config.php')
    for unit in exporter.TIMERS:
        # SMS timer is optional and may not be installed on a fresh destination.
        subprocess.run(['systemctl','disable','--now',unit],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        if exporter.active(unit):raise RuntimeError('Could not pause a destination timer')
    if any(exporter.active(unit) for unit in exporter.WORKERS):raise RuntimeError('Worker started during destination preflight')
    run(['systemctl','stop','php8.3-fpm'])
    # Keep PHP stopped on any failure; the destination remains retryable.
    app = destination / 'var/www/masiha-clinic'
    if app.is_symlink():raise RuntimeError('Unexpected application symlink')
    replacement = app.with_name('masiha-clinic.incoming')
    if replacement.exists():shutil.rmtree(replacement)
    shutil.copytree(staging / 'application/masiha-clinic',replacement)
    if app.exists():shutil.rmtree(app)
    replacement.rename(app)
    for path in ['etc/masiha-clinic','var/lib/masiha-clinic']:
        target = destination / path
        if target.is_symlink():raise RuntimeError('Unexpected private directory symlink')
        if target.exists():shutil.rmtree(target)
        shutil.copytree(staging / 'private' / path,target)
        private_permissions(target)
    password = cfg['password'].encode().hex()
    # Pass the credential over stdin, never in the process arguments/logs.
    sql = "DROP DATABASE IF EXISTS masiha_clinic;CREATE DATABASE masiha_clinic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;CREATE USER IF NOT EXISTS 'masiha_clinic'@'localhost';SET @p=CONVERT(0x"+password+" USING utf8mb4);SET @s=CONCAT('ALTER USER \\'masiha_clinic\\'@\\'localhost\\' IDENTIFIED BY ',QUOTE(@p));PREPARE p FROM @s;EXECUTE p;DEALLOCATE PREPARE p;GRANT ALL ON masiha_clinic.* TO 'masiha_clinic'@'localhost';"
    with (staging / 'restore-error.log').open('wb') as errors:
        run(['mariadb'],input=sql.encode(),stderr=errors)
        process = subprocess.Popen(['mariadb','masiha_clinic'],stdin=subprocess.PIPE,stderr=errors)
        try:
            with gzip.open(staging / 'database.sql.gz','rb') as source:shutil.copyfileobj(source,process.stdin)
        finally:process.stdin.close()
        if process.wait():raise RuntimeError('Database import failed; PHP stays stopped')
    if exporter.database_counts('masiha_clinic') != manifest['table_counts']:raise RuntimeError('Database row counts differ; PHP stays stopped')
    run(['chown','-R','www-data:www-data',str(destination / 'var/lib/masiha-clinic')])
    configdir = destination / 'etc/masiha-clinic';configfile = configdir / 'config.php'
    run(['chown','root:www-data',str(configdir),str(configfile)])
    configdir.chmod(0o750);configfile.chmod(0o640)
    run(['chown','-R','root:root',str(app)])
    for directory,dirs,files in os.walk(app):
        pathlib.Path(directory).chmod(0o755)
        for file in files:
            path = pathlib.Path(directory) / file
            path.chmod(0o755 if path.suffix == '.sh' else 0o644)
    run(['systemctl','start','php8.3-fpm'])
    try:run(['curl','--fail','--silent','--show-error','--max-time','15','-H','Host: '+host,'http://127.0.0.1/health'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    except subprocess.CalledProcessError:
        run(['systemctl','stop','php8.3-fpm']);raise RuntimeError('Health check failed; PHP stays stopped')
    marker.unlink()
    report = destination / 'var/lib/masiha-migration-verified.json'
    report.write_text(json.dumps({'source':manifest['hostname'],'created_at':manifest['created_at'],'table_counts':manifest['table_counts'],'timers':'disabled'},indent=2));report.chmod(0o600)
    print('RESTORE_VERIFIED: code, private files and database row counts restored; importer/SMS timers remain disabled.')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('bundle');parser.add_argument('--host',required=True)
    parser.add_argument('--verify-only',action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:raise RuntimeError('Run with sudo/root')
    if not re.fullmatch(r'[A-Za-z0-9.:-]+',args.host):raise RuntimeError('Invalid destination host')
    os.umask(0o077)
    bundle = pathlib.Path(args.bundle).resolve()
    with tempfile.TemporaryDirectory(prefix='masiha-restore-') as directory:
        staging = pathlib.Path(directory);manifest = prepare(bundle,staging)
        if args.verify_only:print('TRANSFER_VERIFIED: all archive checksums and application manifest match');return
        restore(staging,manifest,args.host)

if __name__ == '__main__':
    try:main()
    except Exception as error:
        # Subprocess arguments contain no secrets; PHP/MariaDB diagnostics are kept private.
        print('RESTORE_FAILED: '+str(error),flush=True);raise SystemExit(1)
