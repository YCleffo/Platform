CREATE TABLE education.file_transfers (
 id uuid PRIMARY KEY DEFAULT uuidv7(),organization_id uuid NOT NULL,account_id uuid NOT NULL,person_id uuid NOT NULL,
 student_id uuid,kind varchar(16) NOT NULL CHECK(kind IN ('file','homework')),resource_id uuid,lesson_id uuid,
 avatar boolean NOT NULL DEFAULT false,name varchar(240) NOT NULL,expected_size bigint NOT NULL CHECK(expected_size BETWEEN 1 AND 10485760),
 expected_sha256 char(64) NOT NULL CHECK(expected_sha256 ~ '^[a-f0-9]{64}$'),staging_key varchar(160) NOT NULL UNIQUE,
 final_key uuid NOT NULL UNIQUE,status varchar(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','aborted')),
 result jsonb,created_at timestamptz NOT NULL DEFAULT now(),expires_at timestamptz NOT NULL DEFAULT now()+interval '1 day',
 confirmed_at timestamptz,
 CHECK((kind='homework' AND resource_id IS NOT NULL AND NOT avatar AND lesson_id IS NULL) OR(kind='file' AND resource_id IS NULL)),
 CHECK((status='confirmed')=(result IS NOT NULL)),
 FOREIGN KEY(organization_id) REFERENCES core.organizations(id),FOREIGN KEY(account_id) REFERENCES core.accounts(id),
 FOREIGN KEY(person_id) REFERENCES core.persons(id)
);
CREATE INDEX education_file_transfer_actor ON education.file_transfers(organization_id,account_id,created_at);
CREATE TABLE education.object_cleanup (
 object_key varchar(160) PRIMARY KEY,queued_at timestamptz NOT NULL DEFAULT now(),attempts integer NOT NULL DEFAULT 0,
 last_error varchar(64),completed_at timestamptz
);
