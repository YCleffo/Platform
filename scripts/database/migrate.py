"""Apply repository migrations using existing runtime containers only."""
import argparse
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[2]


def command(args,sql=None):
    result=subprocess.run(args,cwd=ROOT,input=sql,capture_output=True,text=True,encoding='utf-8',check=True)
    return result.stdout


def container(service):
    value=command(['docker','compose','--profile','control','ps','-q',service]).strip()
    if not value:raise RuntimeError('Required runtime service is not running: '+service)
    obj=json.loads(command(['docker','inspect',value]))[0]
    labels=obj['Config'].get('Labels',{})
    if labels.get('com.docker.compose.project')!='platform' or labels.get('com.docker.compose.service')!=service:
        raise RuntimeError('Unexpected runtime destination')
    return value


def sql(service,query):
    return command(['docker','exec','-i',container(service),'sh','-c',
        'exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],query)


def migrate(plane):
    service='postgres' if plane=='core' else 'control-postgres'
    expected_database='platform' if plane=='core' else 'platform_commercial'
    if sql(service,'SELECT current_database();').strip()!=expected_database:
        raise RuntimeError('Database identity does not match runtime contract')
    if plane=='core':
        sql(service,"CREATE TABLE IF NOT EXISTS public.platform_migrations(migration_name text PRIMARY KEY,applied_at timestamptz NOT NULL DEFAULT now());")
        applied=set(sql(service,'SELECT migration_name FROM public.platform_migrations;').splitlines())
        files=sorted((ROOT/'apps/backend/migrations/sql').glob('*.sql'))
        if applied-{p.name for p in files}:raise RuntimeError('Database contains migrations absent from repository')
        for path in files:
            if path.name in applied:continue
            name=path.name.replace("'","''")
            sql(service,'BEGIN;\n'+path.read_text(encoding='utf-8')+"\nINSERT INTO public.platform_migrations(migration_name) VALUES ('"+name+"');\nCOMMIT;")
            print('Applied '+path.name,flush=True)
    else:
        output=command(['docker','exec',container('control-plane'),'php','bin/console','doctrine:migrations:migrate','--no-interaction'])
        print(output.strip())


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('plane',choices=['core','commercial','all'])
    args=parser.parse_args()
    try:
        for plane in (['core','commercial'] if args.plane=='all' else [args.plane]):migrate(plane)
    except subprocess.CalledProcessError as error:
        raise SystemExit('Migration failed; no credentials or SQL row diagnostics are printed. Exit '+str(error.returncode)) from None
