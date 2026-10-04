<?php
// Root-console audit. Never loads the web application or writes to the database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function clinicAudit(PDO $db): array {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $query = static function(string $sql) use ($db): array { return $db->query($sql)->fetchAll(); };
        $scalar = static function(string $sql) use ($db) { return $db->query($sql)->fetchColumn(); };
        $tables = [];
        foreach ($query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $name = $t['TABLE_NAME'];
            $quoted = '`'.str_replace('`', '``', $name).'`';
            $tables[$name] = ['engine'=>$t['ENGINE'], 'rows'=>(int)$scalar("SELECT COUNT(*) FROM $quoted")];
        }
        $columns = $query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');
        $indexes = $query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
        $map = [];
        foreach ($columns as $c) $map[$c['TABLE_NAME']][$c['COLUMN_NAME']] = true;
        $has = static function(array $needs) use ($map): bool {
            foreach ($needs as $table=>$names) foreach ($names as $name) if (!isset($map[$table][$name])) return false;
            return true;
        };
        $checks = [];
        $check = static function(string $name, array $needs, string $sql) use (&$checks,$has,$scalar): void {
            $checks[$name] = $has($needs) ? (int)$scalar($sql) : null;
        };
        $check('sessions_without_episode',['physio_sessions'=>['episode_id'],'physio_episodes'=>['id']], 'SELECT COUNT(*) FROM physio_sessions s LEFT JOIN physio_episodes e ON e.id=s.episode_id WHERE e.id IS NULL');
        $check('episodes_without_patient',['physio_episodes'=>['pid'],'patients'=>['pid']], 'SELECT COUNT(*) FROM physio_episodes e LEFT JOIN patients p ON p.pid=e.pid WHERE p.pid IS NULL');
        $check('sessions_without_workflow',['physio_sessions'=>['id'],'visit_workflows'=>['session_id']], 'SELECT COUNT(*) FROM physio_sessions s LEFT JOIN visit_workflows w ON w.session_id=s.id WHERE w.session_id IS NULL');
        $check('workflow_status_mismatch',['physio_sessions'=>['id','status'],'visit_workflows'=>['session_id','state']], "SELECT COUNT(*) FROM visit_workflows w JOIN physio_sessions s ON s.id=w.session_id WHERE s.status<>CASE WHEN w.state IN ('visited','discharged') THEN 'done' WHEN w.state='absent' THEN 'absent' WHEN w.state='cancelled' THEN 'cancelled' ELSE 'scheduled' END");
        $check('workflow_event_version_mismatch',['visit_workflows'=>['session_id','version'],'visit_events'=>['session_id','version']], 'SELECT COUNT(*) FROM visit_workflows w LEFT JOIN (SELECT session_id,MAX(version) v,COUNT(*) n FROM visit_events GROUP BY session_id) x ON x.session_id=w.session_id WHERE x.v IS NULL OR x.v<>w.version OR x.n<>w.version+1');
        $check('reversed_observed_times',['visit_workflows'=>['arrived_at','treatment_started_at','treatment_finished_at','departed_at']], 'SELECT COUNT(*) FROM visit_workflows WHERE treatment_started_at<arrived_at OR treatment_finished_at<treatment_started_at OR departed_at<treatment_finished_at');
        $check('payments_without_episode',['physio_payments'=>['episode_id'],'physio_episodes'=>['id']], 'SELECT COUNT(*) FROM physio_payments p LEFT JOIN physio_episodes e ON e.id=p.episode_id WHERE e.id IS NULL');
        $check('movements_without_item',['inventory_movements'=>['item_id'],'inventory_items'=>['id']], 'SELECT COUNT(*) FROM inventory_movements m LEFT JOIN inventory_items i ON i.id=m.item_id WHERE i.id IS NULL');
        $check('negative_known_stock',['inventory_items'=>['id','opening_quantity'],'inventory_movements'=>['item_id','quantity_delta','affects_stock']], 'SELECT COUNT(*) FROM inventory_items i LEFT JOIN (SELECT item_id,SUM(quantity_delta) qty FROM inventory_movements WHERE affects_stock=1 GROUP BY item_id) m ON m.item_id=i.id WHERE i.opening_quantity IS NOT NULL AND i.opening_quantity+COALESCE(m.qty,0)<0');
        $check('invalid_permissions_json',['staff_permissions'=>['permissions']], 'SELECT COUNT(*) FROM staff_permissions WHERE NOT JSON_VALID(permissions)');
        $financial = [];
        if ($has(['physio_payments'=>['amount_toman','voided']])) $financial['native_net_payments_toman'] = (string)$scalar('SELECT COALESCE(SUM(amount_toman),0) FROM physio_payments WHERE voided=0');
        if ($has(['physio_episodes'=>['fee_toman']])) $financial['episode_fees_toman'] = (string)$scalar('SELECT COALESCE(SUM(fee_toman),0) FROM physio_episodes');
        if ($has(['patient_account_ledger'=>['debit_toman','credit_toman','voided']])) $financial['ledger_balance_toman'] = (string)$scalar('SELECT COALESCE(SUM(debit_toman-credit_toman),0) FROM patient_account_ledger WHERE voided=0');
        $concepts = [];
        foreach ($columns as $c) if (preg_match('/room|insurance|bimeh/i', $c['COLUMN_NAME'])) $concepts[] = $c['TABLE_NAME'].'.'.$c['COLUMN_NAME'];
        $roles = $has(['staff'=>['role','active']]) ? $query('SELECT role,active,COUNT(*) AS count FROM staff GROUP BY role,active ORDER BY role,active') : [];
        $required = ['patients','staff','staff_permissions','physio_episodes','physio_sessions','physio_payments','visit_workflows','visit_events','inventory_items','inventory_movements','physio_patient_documents','physio_slots','import_records'];
        return ['format'=>1,'read_only'=>true,'captured_at_utc'=>gmdate('c'),
            'schema_sha256'=>hash('sha256',json_encode([$columns,$indexes],JSON_THROW_ON_ERROR)),
            'tables'=>$tables,'missing_required_tables'=>array_values(array_diff($required,array_keys($tables))),
            'non_transactional_tables'=>array_keys(array_filter($tables,static fn($t)=>$t['engine']!=='InnoDB')),
            'integrity_violations'=>$checks,'financial_totals'=>$financial,'staff_roles'=>$roles,
            'room_insurance_columns'=>$concepts,
            'migration_note'=>'SQL files are repeatable migrations; matching current schema does not prove historical execution order. Unknown checks are null, not zero.'];
    } finally {
        // ROLLBACK also ends the read-only snapshot if a query failed.
        $db->exec('ROLLBACK');
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'') === __FILE__) {
    try {
        $file = getenv('MASIHA_CONFIG') ?: '/etc/masiha-clinic/config.php';
        $cfg = require $file;
        $db = new PDO($cfg['dsn'],$cfg['user'],$cfg['password']);
        $report = clinicAudit($db);
        $root = dirname(__DIR__);
        $report['migration_files'] = [];
        foreach (glob($root.'/deploy/*.sql') as $sql) $report['migration_files'][basename($sql)] = hash_file('sha256',$sql);
        $report['code_differences_from_checkout'] = [];
        foreach (['app','public','deploy','importer'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $item) {
                if (!$item->isFile() || $item->isLink() || !in_array($item->getExtension(),['php','sql','py','js','css','sh'],true)) continue;
                $relative = substr($item->getPathname(),strlen($root)+1);
                $live = '/var/www/masiha-clinic/'.$relative;
                if (!is_file($live) || !hash_equals(hash_file('sha256',$item->getPathname()),hash_file('sha256',$live))) $report['code_differences_from_checkout'][] = $relative;
            }
        }
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {
        // No DSN, SQL error text, file contents or credentials in terminal output.
        fwrite(STDERR,'AUDIT_FAILED: '.get_class($e).PHP_EOL);
        exit(1);
    }
}
