-- Rehearsal candidate, NOT a production cutover script.
-- psql variables: plane, owner_role, runtime_role, database_name.
SELECT set_config('rehearsal.expected_plane', :'plane', false);
SELECT set_config('rehearsal.expected_database', :'database_name', false);
DO $$ BEGIN
 IF current_setting('cluster_name') <> 'platform-upgrade-rehearsal-' || current_setting('rehearsal.expected_plane')
    OR current_database() <> current_setting('rehearsal.expected_database') THEN
   RAISE EXCEPTION 'Refusing role changes outside an isolated rehearsal cluster';
 END IF;
END $$;
REVOKE ALL ON DATABASE :"database_name" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"database_name" TO :"runtime_role";
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SELECT format('REVOKE ALL ON SCHEMA %I FROM PUBLIC; GRANT USAGE ON SCHEMA %I TO %I',nspname,nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
-- Compatibility floor for the currently combined API/worker runtime. No DDL,
-- ownership, TRUNCATE, role membership or migration-history writes are granted.
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %I TO %I',nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
SELECT format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO %I',nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
SELECT format('REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA %I FROM PUBLIC; GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA %I TO %I',nspname,nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I',:'owner_role',nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT USAGE, SELECT ON SEQUENCES TO %I',:'owner_role',nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
-- PostgreSQL's PUBLIC function EXECUTE default must be revoked globally for
-- this owner: a schema-local REVOKE cannot subtract the global default.
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA %I GRANT EXECUTE ON FUNCTIONS TO %I',:'owner_role',nspname,:'runtime_role')
FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial') \gexec
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM :"runtime_role";
SELECT format('GRANT SELECT ON TABLE public.%I TO %I',c.relname,:'runtime_role') FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='public' AND c.relname IN ('platform_migrations','doctrine_migration_versions') \gexec
