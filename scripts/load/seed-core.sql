\set ON_ERROR_STOP on
DO $$ BEGIN
 IF current_database() <> 'platform_load_core' THEN RAISE EXCEPTION 'Load DB only'; END IF;
 IF EXISTS (SELECT 1 FROM core.accounts) THEN RAISE EXCEPTION 'Load fixture requires a fresh database'; END IF;
END $$;
BEGIN;
INSERT INTO core.persons(id, given_name, family_name)
SELECT ('10000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid, 'Load', 'User '||n FROM generate_series(1,10000) n;
INSERT INTO core.accounts(id,person_id,status,label)
SELECT ('20000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,
       ('10000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,'active','Load user '||n FROM generate_series(1,10000) n;
INSERT INTO core.auth_sessions(account_id,session_token_hash,refresh_token_hash,security_stamp,authentication_method,client_type,access_expires_at,expires_at)
SELECT id,sha256(convert_to('load_core_access_'||lpad(right(id::text,12)::bigint::text,26,'0'),'UTF8')),
       sha256(convert_to('load_core_refresh_'||lpad(right(id::text,12)::bigint::text,25,'0'),'UTF8')),
       security_stamp,'password','browser',now()+interval '4 hours',now()+interval '1 day' FROM core.accounts;
COMMIT;
ANALYZE;
