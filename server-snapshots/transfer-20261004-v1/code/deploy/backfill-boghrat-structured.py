#!/usr/bin/env python3
import json
import pathlib
import subprocess
import sys

ROOT=pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT/'importer'))
from parser import record_from_dom

def bridge(record_id,record):
    payload=json.dumps({"op":"rebuild","record_id":record_id,"record":record},ensure_ascii=False)
    p=subprocess.run(["php",str(ROOT/"importer/bridge.php")],input=payload,text=True,capture_output=True)
    if p.returncode:
        raise RuntimeError("bridge_failed:"+p.stderr.strip())
    out=json.loads(p.stdout)
    if not out.get("ok"):
        raise RuntimeError("rebuild_failed:"+json.dumps(out,ensure_ascii=False))

sql="SELECT id,HEX(payload) FROM import_records ORDER BY id"
raw=subprocess.check_output(["mariadb","-N","masiha_clinic","-e",sql],text=True)
ok=skip=0
for line in raw.splitlines():
    if not line.strip():
        continue
    sid,hex_payload=line.split("\t",1)
    data=json.loads(bytes.fromhex(hex_payload).decode("utf-8"))
    row=data.get("list_cells") or []
    personal=str(data.get("personal") or "")
    history=str(data.get("history") or "")
    if len(row)<5 or not personal or not history:
        skip+=1
        continue
    record=record_from_dom(
        row,personal,history,data.get("links") or [],
        int(data.get("page") or 1),int(data.get("row") or 0),
        data.get("source_url") or "https://app.boghrat.com/clinic/secretary/reception/",
        forms=data.get("forms") or {}
    )
    bridge(int(sid),record)
    ok+=1
    if ok%25==0:
        print("BACKFILL",ok,flush=True)
print("BACKFILL_COMPLETE",ok,"SKIPPED",skip)
