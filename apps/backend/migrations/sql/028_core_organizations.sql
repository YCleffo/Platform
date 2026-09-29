CREATE TABLE core.organizations (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    code varchar(120) NOT NULL UNIQUE CHECK (code ~ '^[a-z0-9]+([._-][a-z0-9]+)*$'),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'archived')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    archived_at timestamptz,
    CONSTRAINT chk_organizations_archive CHECK ((status = 'archived') = (archived_at IS NOT NULL))
);

CREATE TABLE core.organization_memberships (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    organization_id uuid NOT NULL REFERENCES core.organizations(id) ON DELETE RESTRICT,
    person_id uuid NOT NULL REFERENCES core.persons(id) ON DELETE RESTRICT,
    status varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'left')),
    joined_at timestamptz NOT NULL DEFAULT now(),
    left_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT chk_memberships_left CHECK ((status = 'left') = (left_at IS NOT NULL)),
    CONSTRAINT chk_memberships_dates CHECK (left_at IS NULL OR left_at >= joined_at)
);
CREATE UNIQUE INDEX uq_organization_memberships_active
    ON core.organization_memberships (organization_id, person_id) WHERE status = 'active';
CREATE INDEX idx_organization_memberships_person ON core.organization_memberships (person_id, organization_id);

INSERT INTO core.permissions (code, name, description, is_system) VALUES
    ('core.organizations.read', 'Read organizations', 'Read Core organizations.', true),
    ('core.organizations.manage', 'Manage organizations', 'Create Core organizations.', true),
    ('core.organization_memberships.read', 'Read organization memberships', 'Read memberships in an authorized organization.', true),
    ('core.organization_memberships.manage', 'Manage organization memberships', 'Add and end memberships in an authorized organization.', true)
ON CONFLICT (code) DO NOTHING;
