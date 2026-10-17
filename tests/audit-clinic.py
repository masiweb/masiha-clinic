"""Read-only aggregate audit tested on a disposable DB, including incomplete schemas."""
import json, os, pathlib, secrets, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parent.parent
db = 'masiha_audit_test_' + secrets.token_hex(4)

def sql(text):
    return subprocess.check_output(['mariadb', '-N', '-e', text], text=True).strip()

def audit():
    code = "require " + json.dumps(str(root / 'deploy/audit-clinic.php')) + ";$d=new PDO(getenv('AUDIT_TEST_DSN'),'root','');echo json_encode(clinicAudit($d));"
    return json.loads(subprocess.check_output(['php','-r',code], text=True, env={**os.environ,'AUDIT_TEST_DSN':'mysql:host='+os.environ.get('MASIHA_TEST_DB_HOST','localhost')+';dbname='+db}))

try:
    sql('CREATE DATABASE '+db+' CHARACTER SET utf8mb4')
    report=audit()
    assert 'patients' in report['missing_required_tables']
    assert report['integrity_violations']['negative_known_stock'] is None
    for name in ['schema','therapy','portal','admin-v2','import','workflow','appointments','history-reconciliation','billing','operations']:
        subprocess.run(['mariadb',db],input=(root/'deploy'/f'{name}.sql').read_text(),text=True,check=True)
    sql(f"""USE {db};
    INSERT INTO patients(pid,fname,lname,phone_cell,address,notes) VALUES(1,'PRIVATE_PATIENT','SECRET_NAME','09123456789','','');
    INSERT INTO staff(id,username,name,password_hash,role) VALUES(1,'PRIVATE_LOGIN','PRIVATE_NAME','SECRET_HASH','admin');
    INSERT INTO physio_episodes(id,pid,diagnosis,body_region,assessment,goals,precautions,exercises,planned_sessions,fee_toman,created_by) VALUES(1,1,'','','','','','',10,1000,1);
    INSERT INTO physio_sessions(id,episode_id,therapist_id,starts_at,ends_at,room,treatment,notes,created_by) VALUES(1,1,1,'2099-01-01 09:00','2099-01-01 10:00','ROOM_SECRET','','',1);
    INSERT INTO physio_payments(episode_id,amount_toman,created_by) VALUES(1,300,1);
    INSERT INTO inventory_items(id,name,opening_quantity) VALUES(1,'SECRET_ITEM',1);
    INSERT INTO inventory_movements(item_id,movement_type,quantity_delta,affects_stock) VALUES(1,'adjustment',-2,1);
    """)
    before=sql(f'USE {db}; CHECKSUM TABLE patients,staff,physio_episodes,physio_sessions,physio_payments,inventory_items,inventory_movements,visit_workflows,visit_events,audit')
    report=audit()
    after=sql(f'USE {db}; CHECKSUM TABLE patients,staff,physio_episodes,physio_sessions,physio_payments,inventory_items,inventory_movements,visit_workflows,visit_events,audit')
    assert before==after, 'audit mutated data'
    assert report['missing_required_tables']==[]
    assert report['integrity_violations']['sessions_without_workflow']==1
    assert report['integrity_violations']['negative_known_stock']==1
    assert report['financial_totals']['native_net_payments_toman']=='300'
    assert report['staff_roles']==[{'role':'admin','active':1,'count':1}]
    assert 'physio_sessions.room' in report['room_insurance_columns']
    for forbidden in ['PRIVATE_','SECRET_','09123456789','ROOM_SECRET']:
        assert forbidden not in json.dumps(report)
    subprocess.run(['mariadb',db],input=(root/'deploy/workflow.sql').read_text(),text=True,check=True)
    assert audit()['integrity_violations']['sessions_without_workflow']==0
    sql(f"UPDATE {db}.visit_workflows SET state='visited',version=5 WHERE session_id=1")
    result=audit()['integrity_violations']
    assert result['workflow_status_mismatch']==1 and result['workflow_event_version_mismatch']==1
    print('PASS read-only audit, missing schema, financial aggregates, data privacy, Room identification, stock and Workflow integrity')
finally:
    sql('DROP DATABASE IF EXISTS '+db)
