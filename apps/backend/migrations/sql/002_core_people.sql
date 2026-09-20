-- ============================================================================
-- 002 — Person and Person Contacts
-- Person = a physical human. It is intentionally separate from Account.
-- ============================================================================

CREATE TABLE core.persons (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    status core.person_status NOT NULL DEFAULT 'active',

    given_name varchar(120) NOT NULL,
    family_name varchar(120) NOT NULL,
    middle_name varchar(120),
    display_name varchar(255),
    birth_date date,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,

    CONSTRAINT chk_persons_given_name_not_blank CHECK (btrim(given_name) <> ''),
    CONSTRAINT chk_persons_family_name_not_blank CHECK (btrim(family_name) <> '')
);

CREATE TABLE core.person_contacts (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    person_id uuid NOT NULL,

    contact_type core.contact_type NOT NULL,
    value varchar(320) NOT NULL,
    normalized_value varchar(320) NOT NULL,

    label varchar(80),
    is_primary boolean NOT NULL DEFAULT false,
    verified_at timestamptz,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_person_contacts_person
        FOREIGN KEY (person_id)
        REFERENCES core.persons(id)
        ON DELETE CASCADE,

    CONSTRAINT chk_person_contacts_value_not_blank CHECK (btrim(value) <> ''),
    CONSTRAINT chk_person_contacts_normalized_not_blank CHECK (btrim(normalized_value) <> '')
);
