"""Run k6 against compose.load.yaml and retain exit code, summary and resources.

Run after migrations and both seed scripts. Does not reset or stop databases.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--vus',type=int,default=10000)
    parser.add_argument('--hold',type=int,default=60)
    parser.add_argument('--pace',type=float,default=5)
    parser.add_argument('--label',default='online-10000')
    args=parser.parse_args()
    if not (1<=args.vus<=10000 and 1<=args.hold<=600 and args.pace>0) or not args.label.replace('-','').replace('_','').isalnum():
        parser.error('Invalid workload or output label')
    directory=ROOT/'runtime/load-results'
    directory.mkdir(parents=True,exist_ok=True)
    summary=directory/'summary.json'
    if summary.exists():
        # Do not allow a failed run to inherit a previous successful summary.
        summary.unlink()
    env=os.environ.copy()
    env.update(TARGET_VUS=str(args.vus),HOLD_SECONDS=str(args.hold),PACE_SECONDS=str(args.pace))
    command=['docker','compose','-p','platform-load','-f','compose.load.yaml','--profile','load','run','--rm','--name','platform-load-generator','k6']
    sources={p:hashlib.sha256((ROOT/p).read_bytes()).hexdigest() for p in ['compose.load.yaml','scripts/load/online.js','scripts/load/run.py']}
    images={}
    for name in ['platform-load-core-1','platform-load-control-1','platform-load-core-db-1','platform-load-commercial-db-1']:
        inspected=subprocess.run(['docker','inspect','--format','{{.Image}}',name],capture_output=True,text=True,timeout=15,check=True)
        images[name]=inspected.stdout.strip()
    started=datetime.datetime.now(datetime.timezone.utc).isoformat()
    resources=[]
    with (directory/(args.label+'.log')).open('w',encoding='utf-8') as log:
        process=subprocess.Popen(command,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
        try:
            while process.poll() is None:
                names=['platform-load-core-1','platform-load-control-1','platform-load-core-db-1',
                       'platform-load-commercial-db-1','platform-load-redis-1','platform-load-generator']
                try:
                    sample=subprocess.run(['docker','stats','--no-stream','--format','{{json .}}']+names,
                                          text=True,capture_output=True,timeout=15)
                    rows=[json.loads(line) for line in sample.stdout.splitlines() if line.startswith('{')]
                    resources.append({'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'containers':rows})
                except (subprocess.TimeoutExpired,json.JSONDecodeError) as error:
                    resources.append({'sampling_error':type(error).__name__})
                time.sleep(3)
        except KeyboardInterrupt:
            subprocess.run(['docker','stop','platform-load-generator'],capture_output=True,timeout=30)
            process.wait(timeout=30)
            raise
    result={'started_utc':started,'finished_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
            'vus_requested':args.vus,'hold_seconds':args.hold,'pace_seconds':args.pace,
            'exit_code':process.returncode,'summary_produced':summary.exists(),
            'generator_limits':{'memory':'12 GiB','cpus':4},'resource_samples':resources}
    result.update(source_sha256=sources,images=images)
    (directory/(args.label+'.resources.json')).write_text(json.dumps(result,indent=2),encoding='utf-8')
    if summary.exists():
        shutil.copyfile(summary,directory/(args.label+'.summary.json'))
    print(json.dumps({k:v for k,v in result.items() if k!='resource_samples'}))
    raise SystemExit(process.returncode)


if __name__=='__main__':
    main()
