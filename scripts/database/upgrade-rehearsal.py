"""Rehearse upgrades of live Platform snapshots without writing to the originals.

Source operations are fixed read-only psql/pg_dump commands. All writes require
unique Compose ownership labels AND the dedicated cluster/database markers.
Dumps and disposable credentials stay in a private, Git-ignored runtime folder.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import uuid

ROOT=Path(__file__).resolve().parents[2]
SCHEMAS={'core':['core','platform_runtime'],'commercial':['commercial']}
SOURCES={'core':'platform-postgres-1','commercial':'platform-control-postgres-1'}
DATABASES={'core':'platform_core_upgrade','commercial':'platform_commercial_upgrade'}


def quote(value):
    return '"'+value.replace('"','""')+'"'


def literal(value):
    return "'"+value.replace("'","''")+"'"


def normalize_check_array_casts(definition):
    """Only normalize PG dump/reparse spelling of varchar literal text arrays.

    Do not strip arbitrary casts, parentheses, names, predicates or CHECKs.
    The two forms have identical text elements and preserve their order.
    """
    value=r"'(?:[^']|'')*'"
    item=r'(?:'+value+r'::character varying(?:::text)?|\('+value+r'::character varying\)::text)'
    array=r'ARRAY\[(?:'+item+r')(?:,\s*'+item+r')*\]'
    pattern=r'(?:\('+array+r'\)::text\[\]|'+array+r'(?:::text\[\])?)'
    return re.sub(pattern,lambda m:'ARRAY['+', '.join(v+'::text' for v in re.findall(value,m.group()))+']',definition)


def schema_differences(actual,expected,path='catalog'):
    if isinstance(actual,dict) and isinstance(expected,dict):
        output=[]
        for key in sorted(actual.keys()|expected.keys()):
            output+=schema_differences(actual.get(key),expected.get(key),path+'/'+key)
        return output
    if isinstance(actual,list) and isinstance(expected,list) and actual+expected and all(isinstance(v,dict) and 'name' in v for v in actual+expected):
        def keyed(values):return {((v['schema']+'.') if 'schema' in v else '')+v['name']:v for v in values}
        return schema_differences(keyed(actual),keyed(expected),path)
    if (path.endswith('/definition') or path.endswith('/predicate')) and isinstance(actual,str) and isinstance(expected,str):
        actual=normalize_check_array_casts(actual);expected=normalize_check_array_casts(expected)
    return [] if actual==expected else [{'path':path,'restored_upgrade':actual,'documented_clean_schema':expected}]


class Rehearsal:
    def __init__(self,keep_running,legacy_fixtures=False):
        self.id=uuid.uuid4().hex[:12]
        self.project='platform-upgrade-'+self.id
        self.directory=ROOT/'runtime/database-upgrade'/self.id
        self.directory.mkdir(parents=True)
        if os.name=='nt':
            account=subprocess.run(['whoami'],capture_output=True,text=True,check=True).stdout.strip()
            subprocess.run(['icacls',str(self.directory),'/inheritance:r','/grant:r',account+':(OI)(CI)F','*S-1-5-18:(OI)(CI)F'],capture_output=True,check=True)
        self.env=os.environ.copy()
        self.env['REHEARSAL_RUN_ID']=self.id
        for plane in SCHEMAS:
            for role in ['ADMIN','MIGRATOR','RUNTIME']:
                self.env['REHEARSAL_'+plane.upper()+'_'+role+'_PASSWORD']=secrets.token_hex(32)
        self.save('disposable-credentials.json',{k:v for k,v in self.env.items() if k.startswith('REHEARSAL_')})
        self.base=['docker','compose','-p',self.project,'-f','compose.upgrade-rehearsal.yaml']
        self.keep=keep_running
        self.legacy_fixtures=legacy_fixtures
        self.result={'run_id':self.id,'project':self.project,'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                     'source_revision':self.command(['git','rev-parse','HEAD']).stdout.strip(),
                     'original_write_operations':0,'dataset':'restored_snapshot_plus_synthetic_legacy_fixtures' if legacy_fixtures else 'restored_snapshot_only',
                     'planes':{},'status':'RUNNING'}

    def save(self,name,data):
        (self.directory/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8',newline='\n')

    def command(self,args,*,data=None,binary=False,label=None,timeout=180,check=True):
        result=subprocess.run(args,cwd=ROOT,env=self.env,input=data,capture_output=True,text=not binary,
                              encoding=None if binary else 'utf-8',timeout=timeout)
        if label:
            stdout=result.stdout if binary else result.stdout.encode('utf-8')
            stderr=result.stderr if binary else result.stderr.encode('utf-8')
            (self.directory/(label+'.stdout')).write_bytes(stdout)
            (self.directory/(label+'.stderr')).write_bytes(stderr)
        if check and result.returncode:
            raise RuntimeError(f'{label or args[0]} failed (exit {result.returncode}); inspect private diagnostics')
        return result

    def compose(self,*args,**kwargs):
        return self.command(self.base+list(args),**kwargs)

    def target(self,plane):
        service='core-db' if plane=='core' else 'commercial-db'
        container=self.compose('ps','-q',service).stdout.strip()
        if not container:
            raise RuntimeError('Missing rehearsal database')
        obj=json.loads(self.command(['docker','inspect',container]).stdout)[0]
        labels=obj['Config'].get('Labels',{})
        if labels.get('com.docker.compose.project')!=self.project or labels.get('platform.rehearsal.run')!=self.id or labels.get('platform.rehearsal')!='true':
            raise RuntimeError('Refusing a non-owned destination')
        if obj['Name'].lstrip('/') in SOURCES.values():
            raise RuntimeError('Original container cannot be a write destination')
        return container

    def target_sql(self,plane,sql,role='rehearsal_admin',*,check=True,label=None,variables=None):
        container=self.target(plane)
        args=['docker','exec','-i']
        if role!='rehearsal_admin':
            kind='MIGRATOR' if role.endswith('_migrator') else 'RUNTIME'
            args+=['-e','PGPASSWORD='+self.env['REHEARSAL_'+plane.upper()+'_'+kind+'_PASSWORD']]
        args += [container,'psql','-X','-qAt','-v','ON_ERROR_STOP=1','-v','VERBOSITY=verbose','-U',role,'-d',DATABASES[plane]]
        if role!='rehearsal_admin':
            args+=['-h','127.0.0.1']
        for key,value in (variables or {}).items():
            args+=['-v',key+'='+value]
        return self.command(args,data=sql,check=check,label=label)

    def assert_target_marker(self,plane):
        value=self.target_sql(plane,"SELECT current_database() || ':' || current_setting('cluster_name');").stdout.strip()
        if value!=DATABASES[plane]+':platform-upgrade-rehearsal-'+plane:
            raise RuntimeError('Destination database/cluster marker mismatch')

    def source_sql(self,plane,sql,snapshot=None):
        # No general source mutation entry point exists. This session is READ ONLY.
        prefix="BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SET LOCAL TIME ZONE 'UTC';"
        if snapshot:
            if not re.fullmatch('[0-9A-Fa-f-]+',snapshot):raise RuntimeError('Invalid exported snapshot identifier')
            prefix="BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SET TRANSACTION SNAPSHOT "+literal(snapshot)+"; SET LOCAL TIME ZONE 'UTC';"
        args=['docker','exec','-i',SOURCES[plane],'sh','-c','exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"']
        return self.command(args,data=prefix+sql+'; COMMIT;').stdout

    @contextmanager
    def source_snapshot(self,plane):
        args=['docker','exec','-i',SOURCES[plane],'sh','-c','exec stdbuf -oL psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"']
        process=subprocess.Popen(args,cwd=ROOT,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,encoding='utf-8')
        try:
            process.stdin.write('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SELECT pg_export_snapshot();\n');process.stdin.flush()
            with ThreadPoolExecutor(max_workers=1) as executor:
                try:
                    snapshot=executor.submit(process.stdout.readline).result(timeout=15).strip()
                except TimeoutError:
                    # Unblock the reader before executor shutdown waits for it.
                    process.kill();process.wait(timeout=10)
                    raise RuntimeError('Read-only snapshot export timed out') from None
            if not re.fullmatch('[0-9A-Fa-f-]+',snapshot):raise RuntimeError('Could not export a read-only snapshot')
            yield snapshot
        finally:
            if process.poll() is None:
                process.stdin.write('ROLLBACK;\n');process.stdin.close();process.wait(timeout=15)
            if process.returncode:
                (self.directory/(plane+'-snapshot.stderr')).write_text(process.stderr.read(),encoding='utf-8')

    def metadata_sql(self,plane):
        history='platform_migrations' if plane=='core' else 'doctrine_migration_versions'
        field='migration_name' if plane=='core' else 'version'
        schemas=','.join(literal(s) for s in SCHEMAS[plane])
        return f"""SELECT json_build_object('database',current_database(),'server_version',current_setting('server_version'),
          'migrations',(SELECT json_agg({field} ORDER BY {field}) FROM public.{history}),
          'tables',(SELECT json_agg(json_build_object('schema',n.nspname,'table',c.relname,'columns',
            (SELECT json_agg(a.attname ORDER BY a.attnum) FROM pg_attribute a WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped)) ORDER BY n.nspname,c.relname)
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ({schemas}) AND c.relkind IN ('r','p')),
          'roles',(SELECT json_agg(json_build_object('role',rolname,'superuser',rolsuper,'createdb',rolcreatedb,'createrole',rolcreaterole,'bypassrls',rolbypassrls,'login',rolcanlogin,'inherit',rolinherit) ORDER BY rolname) FROM pg_roles WHERE rolname NOT LIKE 'pg_%'),
          'unvalidated_constraints',(SELECT count(*) FROM pg_constraint co JOIN pg_namespace n ON n.oid=co.connamespace WHERE n.nspname IN ({schemas}) AND NOT co.convalidated),
          'security_definer_functions',(SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ({schemas}) AND p.prosecdef));"""

    def fingerprints(self,plane,tables,*,snapshot=None,source=False):
        queries=[]
        for t in tables:
            full=quote(t['schema'])+'.'+quote(t['table'])
            columns=','.join(quote(c) for c in t['columns'])
            queries.append(f"""WITH hashes AS (SELECT encode(sha256(convert_to(to_jsonb(r)::text,'UTF8')),'hex') h FROM (SELECT {columns} FROM {full}) r)
            SELECT json_build_object('table',{literal(t['schema']+'.'+t['table'])},'rows',count(*),'sha256',encode(sha256(convert_to(coalesce(string_agg(h,'' ORDER BY h),''),'UTF8')),'hex')) FROM hashes;""")
        sql='\n'.join(queries)
        if source:
            output=self.source_sql(plane,sql,snapshot)
        else:
            output=self.target_sql(plane,"BEGIN READ ONLY; SET LOCAL TIME ZONE 'UTC';"+sql+'COMMIT;').stdout
        return [json.loads(line) for line in output.splitlines() if line.strip()]

    def capture(self,plane):
        with self.source_snapshot(plane) as snapshot:
            metadata=json.loads(self.source_sql(plane,self.metadata_sql(plane),snapshot))
            fingerprints=self.fingerprints(plane,metadata['tables'],snapshot=snapshot,source=True)
            path=self.directory/(plane+'.dump')
            # Binary stdout is written directly, never through PowerShell redirection.
            args=['docker','exec',SOURCES[plane],'sh','-c','exec pg_dump -Fc --snapshot="$1" -U "$POSTGRES_USER" -d "$POSTGRES_DB"','dump',snapshot]
            with path.open('wb') as output:
                p=subprocess.run(args,cwd=ROOT,stdout=output,stderr=subprocess.PIPE,timeout=120)
            if p.returncode:
                (self.directory/(plane+'-dump.stderr')).write_bytes(p.stderr)
                raise RuntimeError('Snapshot dump failed')
        self.save(plane+'-source.json',metadata);self.save(plane+'-source-fingerprints.json',fingerprints)
        self.result['planes'][plane]={'source_migrations':len(metadata['migrations']),'source_tables':len(metadata['tables']),
            'dump_sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'dump_bytes':path.stat().st_size,
            'source_privileged_roles':[r for r in metadata['roles'] if r['superuser'] or r['bypassrls']]}
        print(plane+': captured read-only snapshot ('+str(len(metadata['migrations']))+' migrations)',flush=True)
        return metadata,fingerprints,path

    def restore(self,plane,path):
        self.assert_target_marker(plane)
        empty=self.target_sql(plane,"SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema');").stdout.strip()
        if empty!='0':raise RuntimeError('Refusing restore into a nonempty destination')
        container=self.target(plane)
        self.command(['docker','cp',str(path),container+':/tmp/source.dump'])
        self.command(['docker','exec',container,'pg_restore','--single-transaction','--exit-on-error','--no-owner','--no-acl','-U','rehearsal_admin','-d',DATABASES[plane],'/tmp/source.dump'],label=plane+'-restore')

    def roles(self,plane):
        self.assert_target_marker(plane)
        prefix='core' if plane=='core' else 'commercial'
        owner=prefix+'_owner';migrator=prefix+'_migrator';runtime=prefix+'_runtime'
        mp=self.env['REHEARSAL_'+plane.upper()+'_MIGRATOR_PASSWORD'];rp=self.env['REHEARSAL_'+plane.upper()+'_RUNTIME_PASSWORD']
        # Only clone objects are reassigned. No source role or ACL is altered.
        sql=f"""CREATE ROLE {owner} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
        CREATE ROLE {migrator} LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '{mp}';
        CREATE ROLE {runtime} LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '{rp}';
        GRANT {owner} TO {migrator} WITH INHERIT FALSE, SET TRUE;
        ALTER DATABASE {DATABASES[plane]} OWNER TO {owner};
        GRANT CONNECT ON DATABASE {DATABASES[plane]} TO {migrator};
        REVOKE CONNECT,TEMPORARY ON DATABASE postgres,template1 FROM PUBLIC;
        SELECT format('ALTER SCHEMA %I OWNER TO {owner}',nspname) FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \\gexec
        SELECT format('ALTER TABLE %I.%I OWNER TO {owner}',n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('core','platform_runtime','commercial','public') AND c.relkind IN ('r','p','v','m') \\gexec
        SELECT format('ALTER SEQUENCE %I.%I OWNER TO {owner}',n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('core','platform_runtime','commercial','public') AND c.relkind='S' \\gexec
        SELECT format('ALTER TYPE %I.%I OWNER TO {owner}',n.nspname,t.typname) FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname IN ('core','platform_runtime','commercial') AND t.typtype IN ('e','d') \\gexec
        SELECT format('ALTER FUNCTION %I.%I(%s) OWNER TO {owner}',n.nspname,p.proname,pg_get_function_identity_arguments(p.oid)) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ('core','platform_runtime','commercial') AND p.prokind='f' \\gexec
        """
        self.target_sql(plane,sql,label=plane+'-role-setup')

    def grants(self,plane):
        self.assert_target_marker(plane)
        prefix='core' if plane=='core' else 'commercial'
        sql=(ROOT/'scripts/database/rehearsal/runtime-grants.sql').read_text(encoding='utf-8')
        self.target_sql(plane,sql,variables={'plane':plane,'owner_role':prefix+'_owner','runtime_role':prefix+'_runtime','database_name':DATABASES[plane]},label=plane+'-grants')

    def migrate(self,plane,label):
        self.assert_target_marker(plane)
        service='core-migrate' if plane=='core' else 'control-migrate'
        self.compose('run','--rm','--no-deps',service,label=plane+'-'+label,timeout=240)

    def verify_migration_set(self,plane,after):
        directory=ROOT/('apps/backend/migrations/sql' if plane=='core' else 'apps/control-plane/migrations')
        paths=sorted(directory.glob('*.sql' if plane=='core' else 'Version*.php'))
        expected=sorted(p.name if plane=='core' else 'ControlPlane\\Migrations\\'+p.stem for p in paths)
        if sorted(after['migrations'])!=expected:
            raise RuntimeError(plane+' migration history does not exactly match repository files')
        manifest=[{'path':p.relative_to(ROOT).as_posix(),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()} for p in paths]
        self.save(plane+'-migration-sources.json',manifest)
        self.result['planes'][plane]['exact_migration_set_matches_repository']=True

    def sequences(self,plane):
        names=json.loads(self.target_sql(plane,"SELECT coalesce(json_agg(json_build_object('schema',schemaname,'name',sequencename) ORDER BY schemaname,sequencename),'[]') FROM pg_sequences WHERE schemaname IN ('core','platform_runtime','commercial');").stdout)
        values=[]
        for item in names:
            state=json.loads(self.target_sql(plane,'SELECT row_to_json(s) FROM (SELECT last_value,is_called FROM '+quote(item['schema'])+'.'+quote(item['name'])+') s;').stdout)
            values.append(dict(item,**state))
        return values

    def catalog(self,plane):
        sql=(ROOT/'scripts/database/schema_catalog.sql').read_text(encoding='utf-8')
        actual=json.loads(self.target_sql(plane,'BEGIN READ ONLY;'+sql+'COMMIT;').stdout)
        documented=json.loads((ROOT/'docs/database'/plane/'schema.catalog.json').read_text(encoding='utf-8'))
        fields=['schemas','enums','tables','views','functions']
        changes=schema_differences({f:actual[f] for f in fields},{f:documented[f] for f in fields})
        self.save(plane+'-actual-catalog.json',actual)
        self.save(plane+'-schema-differences.json',changes)
        self.result['planes'][plane]['catalog_matches_documented_dbml_source']=not changes
        self.result['planes'][plane]['substantive_schema_differences']=len(changes)
        self.result['planes'][plane]['raw_catalog_text_equal']=all(actual[f]==documented[f] for f in fields)
        if changes:print(plane+': SCHEMA DRIFT ('+str(len(changes))+' differences); continuing independent checks',flush=True)
        self.result['planes'][plane]['validated_constraints']=sum(len(t['constraints']) for t in actual['tables'])
        self.result['planes'][plane]['triggers']=sum(len(t['triggers']) for t in actual['tables'])

    def legacy_fixture(self,plane,tables):
        self.assert_target_marker(plane)
        sql=(ROOT/'scripts/database/rehearsal'/('legacy-'+plane+'-fixture.sql')).read_text(encoding='utf-8')
        self.target_sql(plane,sql,label=plane+'-legacy-fixture')
        before=self.fingerprints(plane,tables)
        self.save(plane+'-legacy-fingerprints.json',before)
        self.result['planes'][plane]['synthetic_legacy_fixture_inserted_before_upgrade']=True
        return before

    def legacy_assertions(self,plane):
        if plane=='core':
            value=self.target_sql(plane,"""SELECT count(*) FROM core.auth_external_identities e
             JOIN core.auth_providers p ON p.id=e.provider_id WHERE p.code='rehearsal-legacy'
             AND e.created_at=e.linked_at AND e.updated_at=e.linked_at AND e.last_used_at IS NOT DISTINCT FROM e.last_login_at;""").stdout.strip()
            if value!='2':raise RuntimeError('Legacy 032 timestamp backfill did not preserve null/non-null cases')
            self.result['planes'][plane]['legacy_timestamp_backfill_cases']=2
        else:
            values=json.loads(self.target_sql(plane,"""SELECT json_build_object(
             'paid_invoice_minor',(SELECT total_minor FROM commercial.invoices i JOIN commercial.commercial_accounts a ON a.id=i.commercial_account_id WHERE a.code='rehearsal-legacy' AND i.status='paid'),
             'settled_payment_minor',(SELECT amount_minor FROM commercial.payments p JOIN commercial.commercial_accounts a ON a.id=p.commercial_account_id WHERE a.code='rehearsal-legacy' AND p.status='succeeded'),
             'succeeded_refund_minor',(SELECT amount_minor FROM commercial.refunds r JOIN commercial.commercial_accounts a ON a.id=r.commercial_account_id WHERE a.code='rehearsal-legacy' AND r.status='succeeded'),
             'succeeded_receipts',(SELECT count(*) FROM commercial.receipts r JOIN commercial.commercial_accounts a ON a.id=r.commercial_account_id WHERE a.code='rehearsal-legacy' AND r.status='succeeded'));
             """).stdout)
            if values!={'paid_invoice_minor':10000,'settled_payment_minor':10000,'succeeded_refund_minor':5000,'succeeded_receipts':2}:
                raise RuntimeError('Synthetic legacy money/history changed')
            self.result['planes'][plane]['synthetic_legacy_financial_history']=values

    def invalid_legacy_probe(self,plane,tables,baseline):
        if plane!='core':return
        self.assert_target_marker(plane)
        migration=(ROOT/'apps/backend/migrations/sql/033_reconcile_legacy_data_hygiene.sql').read_text(encoding='utf-8')
        sql="""BEGIN;
          INSERT INTO core.persons(given_name,family_name) VALUES ('Synthetic','Invalid') RETURNING id AS person \\gset
          INSERT INTO core.accounts(person_id,status) VALUES (:'person','active') RETURNING id AS account \\gset
          INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value)
          VALUES (:'account','username','x','x');
        """+migration+'\nROLLBACK;'
        p=self.target_sql(plane,sql,check=False,label='core-invalid-legacy-data')
        if p.returncode==0 or '23514' not in p.stderr or 'chk_auth_identifiers_normalized_username' not in p.stderr:
            raise RuntimeError('Invalid old username was not rejected by 033')
        if self.fingerprints(plane,tables)!=baseline:raise RuntimeError('Failed migration did not roll back its fixture/data')
        self.result['planes'][plane]['invalid_legacy_data_rejected_without_rewrite']=True

    def permissions(self,plane):
        prefix='core' if plane=='core' else 'commercial';runtime=prefix+'_runtime'
        schema='core' if plane=='core' else 'commercial';table='persons' if plane=='core' else 'control_users'
        history='platform_migrations' if plane=='core' else 'doctrine_migration_versions'
        statements={
            'schema_ddl':f'CREATE TABLE {schema}.__rehearsal_denied(id integer)',
            'public_ddl':'CREATE TABLE public.__rehearsal_denied(id integer)',
            'alter_owned_table':f'ALTER TABLE {schema}.{table} ADD COLUMN __rehearsal_denied integer',
            'truncate':f'TRUNCATE {schema}.{table} CASCADE',
            'create_role':'CREATE ROLE __rehearsal_denied',
            'self_superuser':f'ALTER ROLE {runtime} SUPERUSER',
            'set_owner':f'SET ROLE {prefix}_owner',
            'grant_owner':f'GRANT {prefix}_owner TO {runtime}',
            'migration_history_write':f'DELETE FROM public.{history} WHERE false',
            'temporary_table':'CREATE TEMP TABLE __rehearsal_denied(id integer)',
            'server_file_read':"SELECT pg_read_file('/etc/passwd',0,1)",
        }
        checks=[]
        for name,statement in statements.items():
            # Every unexpected success is rolled back before failing this check.
            p=self.target_sql(plane,'BEGIN; '+statement+'; ROLLBACK;',role=runtime,check=False,label=plane+'-deny-'+name)
            if p.returncode==0 or '42501' not in p.stderr:
                raise RuntimeError(plane+' privilege denial not established: '+name)
            checks.append({'operation':name,'sqlstate':'42501','result':'DENIED'})
        attributes=self.target_sql(plane,"SELECT row_to_json(r) FROM (SELECT current_user AS role,rolsuper,rolcreatedb,rolcreaterole,rolbypassrls,rolreplication FROM pg_roles WHERE rolname=current_user) r;",role=runtime).stdout
        row=json.loads(attributes)
        if any(row[k] for k in ['rolsuper','rolcreatedb','rolcreaterole','rolbypassrls','rolreplication']):raise RuntimeError('Elevated runtime role')
        self.result['planes'][plane]['runtime_role']=row
        self.result['planes'][plane]['denied_operations']=checks
        boundary=json.loads(self.target_sql(plane,f"""SELECT json_build_object(
          'runtime_memberships',(SELECT count(*) FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname='{runtime}')),
          'runtime_owned_relations',(SELECT count(*) FROM pg_class WHERE relowner=(SELECT oid FROM pg_roles WHERE rolname='{runtime}')),
          'runtime_owned_schemas',(SELECT count(*) FROM pg_namespace WHERE nspowner=(SELECT oid FROM pg_roles WHERE rolname='{runtime}')),
          'owner_can_login',(SELECT rolcanlogin FROM pg_roles WHERE rolname='{prefix}_owner'),
          'migrator_inherits',(SELECT rolinherit FROM pg_roles WHERE rolname='{prefix}_migrator'),
          'migrator_owner_membership',(SELECT json_build_object('inherit',inherit_option,'set',set_option,'admin',admin_option) FROM pg_auth_members WHERE member=(SELECT oid FROM pg_roles WHERE rolname='{prefix}_migrator') AND roleid=(SELECT oid FROM pg_roles WHERE rolname='{prefix}_owner')));
        """).stdout)
        if (boundary['runtime_memberships'] or boundary['runtime_owned_relations'] or boundary['runtime_owned_schemas']
            or boundary['owner_can_login'] or boundary['migrator_inherits']
            or boundary['migrator_owner_membership']!={'inherit':False,'set':True,'admin':False}):
            raise RuntimeError('Owner/migrator/runtime boundary mismatch')
        self.result['planes'][plane]['role_boundary']=boundary
        defaults=json.loads(self.target_sql(plane,f"""BEGIN;
          SET LOCAL ROLE {prefix}_owner;
          CREATE TABLE {schema}.__rehearsal_defaults(id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY);
          CREATE FUNCTION {schema}.__rehearsal_defaults() RETURNS boolean LANGUAGE sql AS 'SELECT true';
          RESET ROLE;
          SET LOCAL ROLE {runtime};
          INSERT INTO {schema}.__rehearsal_defaults DEFAULT VALUES;
          SELECT json_build_object('table_insert',count(*)=1,
             'function_execute',{schema}.__rehearsal_defaults(),
             'public_function_execute',(SELECT coalesce(bool_or(a.grantee=0 AND a.privilege_type='EXECUTE'),false)
               FROM pg_proc p CROSS JOIN LATERAL aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
               WHERE p.oid='{schema}.__rehearsal_defaults()'::regprocedure)) FROM {schema}.__rehearsal_defaults;
          RESET ROLE; ROLLBACK;
        """,label=plane+'-default-acls').stdout)
        if defaults!={'table_insert':True,'function_execute':True,'public_function_execute':False}:
            raise RuntimeError('New owner-object default ACL mismatch')
        self.result['planes'][plane]['new_object_default_acls']=defaults

    def application_probe(self,plane,label):
        service='core' if plane=='core' else 'control'
        p=self.compose('exec','-T',service,'php','/rehearsal/'+plane+'-probe.php',label=plane+'-'+label)
        result=json.loads(p.stdout)
        if result.get('status')!='PASS':raise RuntimeError('Application probe failed')
        self.result['planes'][plane][label]=result

    def application_state(self,service):
        container=self.compose('ps','-q',service).stdout.strip()
        obj=json.loads(self.command(['docker','inspect',container]).stdout)[0]
        labels=obj['Config'].get('Labels',{})
        if labels.get('com.docker.compose.project')!=self.project or labels.get('platform.rehearsal.run')!=self.id:
            raise RuntimeError('Application is not owned by rehearsal')
        return {'started_at':obj['State']['StartedAt'],'image_id':obj['Image'],
                'networks':sorted(obj['NetworkSettings']['Networks']),
                'published_ports':obj['NetworkSettings']['Ports']}

    def http(self,plane,label):
        service,port=('core','80') if plane=='core' else ('control','8000')
        p=self.compose('exec','-T',service,'php','/rehearsal/http-probe.php',port,label=plane+'-http-'+label,timeout=100)
        self.result['planes'][plane]['http_'+label]=json.loads(p.stdout)

    def restart(self):
        services=['core','control','core-db','commercial-db']
        before={s:self.application_state(s) for s in services}
        for plane,service,opposite in [('core','core','commercial-db'),('commercial','control','core-db')]:
            self.http(plane,'before_restart')
            self.application_probe(plane,'application_probe_before_restart')
            # DNS isolation supplements separate, internal network inspections.
            p=self.compose('exec','-T',service,'php','-r',
                "if (gethostbynamel($argv[1])!==false) exit(1); echo 'UNRESOLVED';",opposite)
            if p.stdout!='UNRESOLVED':raise RuntimeError('Cross-plane database DNS unexpectedly resolved')
        if set(before['core']['networks']) & set(before['control']['networks']):
            raise RuntimeError('Applications share a network')
        for state in before.values():
            if any(state['published_ports'].values()):raise RuntimeError('Unexpected published host port')
            for network in state['networks']:
                info=json.loads(self.command(['docker','network','inspect',network]).stdout)[0]
                if not info['Internal']:raise RuntimeError('Rehearsal network has external access')
        self.compose('restart','core','control',label='restart-applications',timeout=90)
        after={s:self.application_state(s) for s in services}
        for plane,service in [('core','core'),('commercial','control')]:
            if before[service]['started_at']==after[service]['started_at']:raise RuntimeError('Application did not restart')
            if before[service+'-db' if service=='core' else 'commercial-db']['started_at']!=after[service+'-db' if service=='core' else 'commercial-db']['started_at']:
                raise RuntimeError('PostgreSQL unexpectedly restarted')
            self.http(plane,'after_restart')
            self.application_probe(plane,'application_probe_after_restart')
        self.result['restart']={'status':'PASS','before':before,'after':after,'postgresql_kept_running':True,
                                'separate_internal_networks':True,'opposite_database_dns_unresolved':True}

    def run(self):
        self.compose('build','core','control',label='build',timeout=360)
        self.compose('up','-d','--wait','core-db','commercial-db','redis',label='start-databases')
        for plane in SCHEMAS:
            before,expected,path=self.capture(plane)
            self.restore(plane,path)
            if self.fingerprints(plane,before['tables'])!=expected:raise RuntimeError(plane+' restored data do not match exported snapshot')
            self.result['planes'][plane]['restore_exact_snapshot_match']=True
            self.invalid_legacy_probe(plane,before['tables'],expected)
            if self.legacy_fixtures:expected=self.legacy_fixture(plane,before['tables'])
            restored_sequences=self.sequences(plane)
            self.roles(plane)
            self.migrate(plane,'upgrade')
            after=json.loads(self.target_sql(plane,self.metadata_sql(plane)).stdout)
            self.verify_migration_set(plane,after)
            self.catalog(plane)
            if self.legacy_fixtures:self.legacy_assertions(plane)
            after_sequences=self.sequences(plane)
            if any(state not in after_sequences for state in restored_sequences):raise RuntimeError('Migration changed an existing sequence state')
            self.result['planes'][plane]['restored_sequence_states_preserved']=len(restored_sequences)
            actual=self.fingerprints(plane,before['tables'])
            if actual!=expected:
                self.save(plane+'-projection-after.json',actual)
                raise RuntimeError(plane+' pre-existing rows or fields changed')
            self.result['planes'][plane].update(target_migrations=len(after['migrations']),target_tables=len(after['tables']),preexisting_rows_and_fields_preserved=True)
            if after['unvalidated_constraints']!=0:raise RuntimeError('Unvalidated constraints remain')
            full=self.fingerprints(plane,after['tables']);self.save(plane+'-post-upgrade-fingerprints.json',full)
            self.migrate(plane,'repeat')
            if self.fingerprints(plane,after['tables'])!=full:raise RuntimeError('Repeat migration changed data')
            self.result['planes'][plane]['repeat_migrations_data_unchanged']=True
            self.save(plane+'-upgraded.json',after)
            self.grants(plane)
            self.permissions(plane)
            print(plane+': upgraded and preserved all original rows/fields; restricted role denials passed',flush=True)
        self.compose('up','-d','core','control',label='start-applications')
        self.restart()
        for plane in SCHEMAS:
            after=json.loads((self.directory/(plane+'-upgraded.json')).read_text(encoding='utf-8'))
            if self.fingerprints(plane,after['tables'])!=json.loads((self.directory/(plane+'-post-upgrade-fingerprints.json')).read_text(encoding='utf-8')):
                raise RuntimeError('Application probe did not fully roll back')
            self.result['planes'][plane]['application_probe_rolled_back_exactly']=True
            source_before=json.loads((self.directory/(plane+'-source.json')).read_text(encoding='utf-8'))
            source_after=json.loads(self.source_sql(plane,self.metadata_sql(plane)))
            if source_before!=source_after:raise RuntimeError('Source metadata changed during rehearsal')
            self.result['planes'][plane]['source_history_schema_roles_unchanged']=True
        self.result['status']='FAIL_SCHEMA_DRIFT' if any(not p['catalog_matches_documented_dbml_source'] for p in self.result['planes'].values()) else 'PASS'


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keep-running',action='store_true')
    parser.add_argument('--legacy-fixtures',action='store_true',help='Separate run: add synthetic history to isolated restored copies before upgrading')
    args=parser.parse_args()
    rehearsal=Rehearsal(args.keep_running,args.legacy_fixtures)
    print('Rehearsal '+rehearsal.id+'; private outputs '+str(rehearsal.directory),flush=True)
    try:
        rehearsal.run()
    except Exception as error:
        rehearsal.result.update(status='FAIL',failure=str(error))
        print(str(error),flush=True)
    finally:
        rehearsal.result['finished_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
        rehearsal.save('result.json',rehearsal.result)
        if not rehearsal.keep:
            rehearsal.compose('stop',check=False,label='stop')
    print(json.dumps({'status':rehearsal.result['status'],'run_id':rehearsal.id,'original_write_operations':0}),flush=True)
    raise SystemExit(0 if rehearsal.result['status']=='PASS' else 1)


if __name__=='__main__':
    main()
