"""Shared types for the JSON emitted by schema_catalog.sql and saved snapshots."""

import json
from typing import NotRequired, TypedDict, cast


class NamedDefinition(TypedDict):
    name: str
    definition: str


class SchemaDefinition(NamedDefinition):
    schema: str


class EnumDefinition(TypedDict):
    schema: str
    name: str
    values: list[str]


class ColumnDefinition(TypedDict):
    name: str
    type: str
    type_schema: str
    type_name: str
    is_enum: bool
    nullable: bool
    default: str | None
    identity: str
    generated: str
    comment: str | None


class ConstraintDefinition(NamedDefinition):
    kind: str
    columns: list[str] | None
    deferrable: bool
    initially_deferred: bool
    target_schema: str | None
    target_table: str | None
    target_columns: list[str] | None
    on_update: str
    on_delete: str


class IndexDefinition(NamedDefinition):
    primary: bool
    unique: bool
    predicate: str | None
    method: str
    key_count: int
    attribute_count: int
    keys: list[str]


class PolicyDefinition(TypedDict):
    name: str
    command: str
    using: str | None
    check: str | None


class TableDefinition(TypedDict):
    schema: str
    name: str
    comment: str | None
    rls: bool
    force_rls: bool
    columns: list[ColumnDefinition]
    constraints: list[ConstraintDefinition]
    indexes: list[IndexDefinition]
    triggers: list[NamedDefinition]
    policies: list[PolicyDefinition]


class MigrationSource(TypedDict):
    path: str
    sha256: str


class Catalog(TypedDict):
    server_version: str
    schemas: list[str]
    enums: list[EnumDefinition]
    tables: list[TableDefinition]
    views: list[SchemaDefinition]
    functions: list[SchemaDefinition]
    applied_migrations: NotRequired[list[str]]
    migration_sources: NotRequired[list[MigrationSource]]


def decode_catalog(content: str) -> Catalog:
    """Decode the internal SQL contract; JSON itself carries no Python types."""
    value: object = json.loads(content)
    if not isinstance(value, dict):
        raise ValueError("PostgreSQL catalog must be a JSON object.")
    catalog = cast(Catalog, value)
    for field in ("schemas", "enums", "tables", "views", "functions"):
        if not isinstance(catalog.get(field), list):
            raise ValueError(f"PostgreSQL catalog field {field!r} must be an array.")
    return catalog


def decode_migration_history(content: str) -> list[str]:
    value: object = json.loads(content)
    if not isinstance(value, list):
        raise ValueError("Migration history must be a JSON array.")
    items = cast(list[object], value)
    if not all(isinstance(item, str) for item in items):
        raise ValueError("Migration history entries must be strings.")
    return cast(list[str], items)
