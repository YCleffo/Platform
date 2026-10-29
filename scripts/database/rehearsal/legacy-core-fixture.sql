-- Synthetic pre-032 compatibility coverage. NEVER run against an original DB.
BEGIN;
DO $$ BEGIN
 IF current_database() <> 'platform_core_upgrade'
 OR current_setting('cluster_name') <> 'platform-upgrade-rehearsal-core' THEN
   RAISE EXCEPTION 'Legacy fixture requires isolated rehearsal Core';
 END IF;
 IF EXISTS (SELECT 1 FROM public.platform_migrations WHERE migration_name LIKE '032_%') THEN
   RAISE EXCEPTION 'Legacy fixture must precede migration 032';
 END IF;
END $$;
INSERT INTO core.auth_providers(code,protocol,display_name)
VALUES ('rehearsal-legacy','oidc','Synthetic upgrade fixture') RETURNING id AS provider \gset
INSERT INTO core.persons(given_name,family_name) VALUES ('Synthetic','Legacy') RETURNING id AS person \gset
INSERT INTO core.accounts(person_id,status,label) VALUES (:'person','active','Synthetic legacy fixture') RETURNING id AS account \gset
INSERT INTO core.auth_external_identities(account_id,provider_id,provider_subject,linked_at,last_login_at)
VALUES (:'account',:'provider','synthetic-subject-1','2026-09-01T10:00:00Z','2026-09-20T11:30:00Z');
INSERT INTO core.persons(given_name,family_name) VALUES ('Synthetic','NoLogin') RETURNING id AS person2 \gset
INSERT INTO core.accounts(person_id,status,label) VALUES (:'person2','active','Synthetic null-login fixture') RETURNING id AS account2 \gset
INSERT INTO core.auth_external_identities(account_id,provider_id,provider_subject,linked_at,last_login_at)
VALUES (:'account2',:'provider','synthetic-subject-2','2026-09-02T10:00:00Z',NULL);
COMMIT;
