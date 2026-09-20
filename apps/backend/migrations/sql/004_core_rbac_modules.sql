-- ============================================================================
-- 004 — RBAC and module registry
-- Domain modules may depend on Core. Core never depends on domain modules.
-- ============================================================================

CREATE TABLE core.roles (
    id uuid PRIMARY KEY DEFAULT uuidv7(),

    code varchar(120) NOT NULL,
    name varchar(160) NOT NULL,
    description text,

    is_system boolean NOT NULL DEFAULT false,
    is_protected boolean NOT NULL DEFAULT false,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT chk_roles_code_not_blank CHECK (btrim(code) <> ''),
    CONSTRAINT chk_roles_name_not_blank CHECK (btrim(name) <> '')
);

CREATE TABLE core.modules (
    id uuid PRIMARY KEY DEFAULT uuidv7(),

    code varchar(100) NOT NULL,
    name varchar(160) NOT NULL,
    description text,

    version varchar(40) NOT NULL,
    settings_schema_version integer NOT NULL DEFAULT 1,
    enabled boolean NOT NULL DEFAULT false,

    installed_at timestamptz NOT NULL DEFAULT now(),
    enabled_at timestamptz,
    disabled_at timestamptz,

    CONSTRAINT chk_modules_code_not_blank CHECK (btrim(code) <> ''),
    CONSTRAINT chk_modules_name_not_blank CHECK (btrim(name) <> ''),
    CONSTRAINT chk_modules_version_not_blank CHECK (btrim(version) <> ''),
    CONSTRAINT chk_modules_settings_schema_version CHECK (settings_schema_version > 0)
);

CREATE TABLE core.permissions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    module_id uuid,

    code varchar(180) NOT NULL,
    name varchar(180) NOT NULL,
    description text,
    is_system boolean NOT NULL DEFAULT false,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT fk_permissions_module
        FOREIGN KEY (module_id)
        REFERENCES core.modules(id)
        ON DELETE SET NULL,

    CONSTRAINT chk_permissions_code_not_blank CHECK (btrim(code) <> ''),
    CONSTRAINT chk_permissions_name_not_blank CHECK (btrim(name) <> '')
);

CREATE TABLE core.role_permissions (
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL,

    granted_at timestamptz NOT NULL DEFAULT now(),
    granted_by_account_id uuid,

    CONSTRAINT pk_role_permissions PRIMARY KEY (role_id, permission_id),

    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id)
        REFERENCES core.roles(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id)
        REFERENCES core.permissions(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_role_permissions_granted_by
        FOREIGN KEY (granted_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL
);

CREATE TABLE core.account_roles (
    account_id uuid NOT NULL,
    role_id uuid NOT NULL,

    assigned_at timestamptz NOT NULL DEFAULT now(),
    assigned_by_account_id uuid,
    expires_at timestamptz,

    CONSTRAINT pk_account_roles PRIMARY KEY (account_id, role_id),

    CONSTRAINT fk_account_roles_account
        FOREIGN KEY (account_id)
        REFERENCES core.accounts(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_account_roles_role
        FOREIGN KEY (role_id)
        REFERENCES core.roles(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_account_roles_assigned_by
        FOREIGN KEY (assigned_by_account_id)
        REFERENCES core.accounts(id)
        ON DELETE SET NULL
);
