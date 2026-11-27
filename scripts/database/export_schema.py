"""Export DBML from a migrated PostgreSQL catalog, or verify saved DBML.

Uses psql (local or in Compose); reads schema metadata only. Python stdlib only.
JSON retains checks, partial/expression indexes, triggers and functions which a
DBML diagram cannot fully express. Never infer a foreign key from a column name.
"""

import argparse
import hashlib
import json
import re
import subprocess
import sys
from collections.abc import Sequence
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.database.catalog_types import (
    Catalog,
    MigrationSource,
    decode_catalog,
    decode_migration_history,
)

TARGETS = {
    "core": ("core", "docs/database/core/Core_v2.dbml"),
    "runtime": ("platform_runtime", "docs/database/runtime/Platform_Runtime_v1.dbml"),
    "commercial": (
        "commercial",
        "docs/database/commercial/Commercial_ControlPlane_v2.dbml",
    ),
    "education": ("education", "docs/database/education/Education_v1.dbml"),
    "device_management": ("device_management", "docs/database/device_management/DeviceManagement_v1.dbml"),
    "storage": ("storage", "docs/database/storage/Storage_v1.dbml"),
    "chat": ("chat", "docs/database/chat/Chat_v1.dbml"),
    "realtime": ("realtime", "docs/database/realtime/Realtime_v1.dbml"),
    "notifications": ("notifications", "docs/database/notifications/Notifications_v1.dbml"),
    "activity": ("activity", "docs/database/activity/Activity_v1.dbml"),
    "workspace": ("workspace", "docs/database/workspace/Workspace_v1.dbml"),
}

COMMENT_TRANSLATIONS: dict[str, str] = json.loads(
    (ROOT / "docs/database/comment-translations.ru.json").read_text(encoding="utf-8")
)

PLANE_SCHEMAS = {
    "core": {"core", "platform_runtime"},
    "commercial": {"commercial"},
    "education": {"education"},
    "device_management": {"device_management"},
    "storage": {"storage"},
    "chat": {"chat"},
    "realtime": {"realtime"},
    "notifications": {"notifications"},
    "activity": {"activity"},
    "workspace": {"workspace"},
}

SNAPSHOTS = {
    "core": "docs/database/core/schema.catalog.json",
    "commercial": "docs/database/commercial/schema.catalog.json",
    "education": "docs/database/education/schema.catalog.json",
    "device_management": "docs/database/device_management/schema.catalog.json",
    "storage": "docs/database/storage/schema.catalog.json",
    "chat": "docs/database/chat/schema.catalog.json",
    "realtime": "docs/database/realtime/schema.catalog.json",
    "notifications": "docs/database/notifications/schema.catalog.json",
    "activity": "docs/database/activity/schema.catalog.json",
    "workspace": "docs/database/workspace/schema.catalog.json",
}

PLANE_TARGETS = {
    "core": ["core", "runtime"],
    "commercial": ["commercial"],
    "education": ["education"],
    "device_management": ["device_management"],
    "storage": ["storage"],
    "chat": ["chat"],
    "realtime": ["realtime"],
    "notifications": ["notifications"],
    "activity": ["activity"],
    "workspace": ["workspace"],
}


def quote(value: object) -> str:
    return (
        "'"
        + str(value)
        .replace("\\", "\\\\")
        .replace("'", "\\'")
        .replace("\r", "")
        .replace("\n", "\\n")
        + "'"
    )


def identifier(value: str) -> str:
    return (
        value
        if re.fullmatch(r"[A-Za-z_][A-Za-z_0-9]*", value)
        else '"' + value.replace('"', '\\"') + '"'
    )


def fields(values: Sequence[str]) -> str:
    result = ", ".join(identifier(v) for v in values)
    return "(" + result + ")" if len(values) > 1 else result


def render(catalog: Catalog, schema: str) -> str:
    tables = [t for t in catalog["tables"] if t["schema"] == schema]
    lines = [
        "// СФОРМИРОВАНО scripts/database/export_schema.py; не редактировать вручную.",
        "// Только реальные объекты PostgreSQL. Полное определение SQL: schema.catalog.json.",
        "Project " + schema + " {",
        "  database_type: 'PostgreSQL'",
        "  Note: 'Каталог PostgreSQL после миграций. Межбазовые идентификаторы не являются внешними ключами.'",
        "}",
        "",
    ]
    external_columns: dict[tuple[str, str], set[str]] = {}
    for table in tables:
        for constraint in table["constraints"]:
            if constraint["kind"] == "f" and constraint["target_schema"] != schema:
                target_schema = constraint["target_schema"]
                target_table = constraint["target_table"]
                if target_schema is None or target_table is None:
                    raise ValueError("Foreign key target is incomplete: " + constraint["name"])
                key = (target_schema, target_table)
                external_columns.setdefault(key, set()).update(constraint["target_columns"] or [])
    for external in catalog.get("external_tables", []) + catalog["tables"]:
        key = (external["schema"], external["name"])
        if key not in external_columns:
            continue
        lines += ["// External reference: only the actual FK target columns are shown.",
                  "Table " + external["schema"] + "." + identifier(external["name"]) + " {"]
        for column in external["columns"]:
            if column["name"] in external_columns[key]:
                lines.append("  " + identifier(column["name"]) + " " + column["type"].replace(" ", "_") + " [not null]")
        lines += ["  Note: 'External FK target; full definition belongs to its own schema catalog.'", "}", ""]
    for enum in catalog["enums"]:
        if enum["schema"] == schema:
            lines += ["Enum " + schema + "." + identifier(enum["name"]) + " {"]
            lines += ['  "' + v.replace('"', '\\"') + '"' for v in enum["values"]]
            lines += ["}", ""]
    for table in tables:
        lines += ["Table " + schema + "." + identifier(table["name"]) + " {"]
        empty_columns: list[str] = []
        primary = next(
            (
                c["columns"] or empty_columns
                for c in table["constraints"]
                if c["kind"] == "p"
            ),
            empty_columns,
        )
        for column in table["columns"]:
            sql_type = column["type"]
            dbml_type = (
                column["type_schema"] + "." + identifier(column["type_name"])
                if column["is_enum"]
                else sql_type.replace(" ", "_")
            )
            # DBML quoted type preserves multiword and array PostgreSQL types.
            if " " in sql_type or "[" in sql_type:
                dbml_type = '"' + sql_type + '"'
            options = ["not null" if not column["nullable"] else "null"]
            if len(primary) == 1 and column["name"] in primary:
                options.insert(0, "pk")
            notes: list[str] = []
            if column["default"] is not None:
                options.append(
                    "default: `" + column["default"].replace("`", "\\`") + "`"
                )
            if column["identity"]:
                notes.append("Автонумерация PostgreSQL: " + column["identity"])
            if column["generated"]:
                notes.append("Вычисляемое поле PostgreSQL: " + column["generated"])
            if column["comment"]:
                notes.append(COMMENT_TRANSLATIONS.get(column["comment"], column["comment"]))
            if notes:
                options.append("note: " + quote("; ".join(notes)))
            lines.append(
                "  "
                + identifier(column["name"])
                + " "
                + dbml_type
                + " ["
                + ", ".join(options)
                + "]"
            )
        indexes: list[str] = []
        if len(primary) > 1:
            indexes.append("    " + fields(primary) + " [pk]")
        column_names = {c["name"] for c in table["columns"]}
        for index in table["indexes"]:
            # A partial unique index is not an unconditional UNIQUE constraint.
            # DBML cannot preserve INCLUDE/opclass/predicate semantics; retain
            # exact SQL in table notes and the catalog instead of changing it.
            if (
                index["primary"]
                or index["predicate"]
                or index["key_count"] != index["attribute_count"]
                or index["method"] not in ("btree", "hash")
            ):
                continue
            if any(k not in column_names for k in index["keys"]):
                continue
            options = ["name: " + quote(index["name"]), "type: " + index["method"]]
            if index["unique"]:
                options.append("unique")
            indexes.append(
                "    " + fields(index["keys"]) + " [" + ", ".join(options) + "]"
            )
        if indexes:
            lines += ["  indexes {"] + indexes + ["  }"]
        notes = [COMMENT_TRANSLATIONS.get(table["comment"], table["comment"])] if table["comment"] else []
        notes += [
            c["name"] + ": " + c["definition"]
            for c in table["constraints"]
            if c["kind"] != "p"
        ]
        notes += [i["definition"] for i in table["indexes"] if not i["primary"]]
        notes += [t["definition"] for t in table["triggers"]]
        if table["rls"] or table["force_rls"]:
            notes.append(
                "Защита строк: " + json.dumps(table["policies"], ensure_ascii=False)
            )
        if notes:
            lines.append("  Note: " + quote("\n".join(notes)))
        lines += ["}", ""]
    actions = {
        "a": "no action",
        "r": "restrict",
        "c": "cascade",
        "n": "set null",
        "d": "set default",
    }
    for table in tables:
        for c in table["constraints"]:
            if c["kind"] == "f":
                source_columns = c["columns"]
                target_schema = c["target_schema"]
                target_table = c["target_table"]
                target_columns = c["target_columns"]
                if (
                    source_columns is None
                    or target_schema is None
                    or target_table is None
                    or target_columns is None
                ):
                    raise ValueError("Foreign key metadata is incomplete: " + c["name"])
                lines.append(
                    "Ref: "
                    + schema
                    + "."
                    + identifier(table["name"])
                    + "."
                    + fields(source_columns)
                    + " > "
                    + target_schema
                    + "."
                    + identifier(target_table)
                    + "."
                    + fields(target_columns)
                    + " [delete: "
                    + actions[c["on_delete"]]
                    + ", update: "
                    + actions[c["on_update"]]
                    + "]"
                )
    return "\n".join(lines) + "\n"


def restrict_catalog(catalog: Catalog, plane: str) -> Catalog:
    allowed = PLANE_SCHEMAS[plane]
    external_targets = {
        (constraint["target_schema"], constraint["target_table"])
        for table in catalog["tables"] if table["schema"] in allowed
        for constraint in table["constraints"]
        if constraint["kind"] == "f" and constraint["target_schema"] not in allowed
    }
    if external_targets:
        catalog["external_tables"] = [
            table for table in catalog.get("external_tables", []) + catalog["tables"]
            if (table["schema"], table["name"]) in external_targets
        ]
    catalog["schemas"] = [schema for schema in catalog["schemas"] if schema in allowed]
    catalog["enums"] = [item for item in catalog["enums"] if item["schema"] in allowed]
    catalog["tables"] = [
        item for item in catalog["tables"] if item["schema"] in allowed
    ]
    catalog["views"] = [item for item in catalog["views"] if item["schema"] in allowed]
    catalog["functions"] = [
        item for item in catalog["functions"] if item["schema"] in allowed
    ]
    return catalog


def migration_manifest(plane: str) -> list[MigrationSource]:
    if plane == "commercial":
        directory, pattern = "apps/control-plane/migrations", "Version*.php"
    elif plane in ("education", "device_management", "storage", "chat", "realtime", "notifications", "activity", "workspace"):
        directory, pattern = f"apps/backend/migrations/{plane}", "*.sql"
    else:
        directory, pattern = "apps/backend/migrations/sql", "*.sql"
    return [
        {
            "path": p.relative_to(ROOT).as_posix(),
            "sha256": hashlib.sha256(p.read_bytes().replace(b"\r\n", b"\n")).hexdigest(),
        }
        for p in sorted((ROOT / directory).glob(pattern))
    ]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--plane",
        choices=["core", "commercial", "education", "device_management", "storage", "chat", "realtime", "notifications", "activity", "workspace"],
        required=True,
    )
    parser.add_argument("--from-snapshot", action="store_true")
    parser.add_argument(
        "--check", action="store_true", help="Read-only comparison; exit 1 on drift"
    )
    source = parser.add_mutually_exclusive_group()
    source.add_argument("--psql", help="Local psql executable instead of Compose")
    source.add_argument("--container", help="Read metadata from this exact PostgreSQL container, including an isolated test stack")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", default="5432")
    parser.add_argument("--database")
    parser.add_argument("--user")
    args = parser.parse_args()
    plane = args.plane
    snapshot = ROOT / SNAPSHOTS[plane]
    if args.from_snapshot:
        catalog = restrict_catalog(
            decode_catalog(snapshot.read_text(encoding="utf-8")), plane
        )
    else:
        database = args.database or (
            "platform_commercial" if plane == "commercial" else "platform"
        )
        user = args.user or (
            "platform_control" if plane == "commercial" else "platform_app"
        )
        command = (
            [args.psql, "-h", args.host, "-p", str(args.port)]
            if args.psql
            else ["docker", "exec", "-i", args.container, "psql"]
            if args.container
            else [
                "docker",
                "compose",
                "--profile",
                "control",
                "exec",
                "-T",
                "control-postgres" if plane == "commercial" else "postgres",
                "psql",
            ]
        )
        command += [
            "-X",
            "-A",
            "-t",
            "-v",
            "ON_ERROR_STOP=1",
            "-U",
            user,
            "-d",
            database,
        ]
        result = subprocess.run(
            command,
            input=(Path(__file__).with_name("schema_catalog.sql")).read_text(
                encoding="utf-8"
            ),
            text=True,
            encoding="utf-8",
            capture_output=True,
            timeout=60,
            check=True,
        )
        catalog = restrict_catalog(decode_catalog(result.stdout), plane)
        if plane == "core":
            history_sql = "SELECT COALESCE(json_agg(migration_name ORDER BY migration_name),'[]') FROM public.platform_migrations;"
        elif plane == "commercial":
            history_sql = "SELECT COALESCE(json_agg(version ORDER BY version),'[]') FROM public.doctrine_migration_versions;"
        else:
            history_sql = f"SELECT COALESCE(json_agg(name ORDER BY name),'[]') FROM {plane}.module_migrations;"
        history = subprocess.run(
            command,
            input=history_sql,
            text=True,
            encoding="utf-8",
            capture_output=True,
            timeout=60,
            check=True,
        )
        catalog["applied_migrations"] = decode_migration_history(history.stdout)
        catalog["migration_sources"] = migration_manifest(plane)
    if catalog.get("migration_sources") != migration_manifest(plane):
        raise SystemExit(
            "Migration source drift: capture a fresh catalog from a fully migrated database."
        )
    applied = sorted(
        v.rsplit("\\", 1)[-1] for v in catalog.get("applied_migrations", [])
    )
    expected = sorted(
        Path(m["path"]).stem if plane == "commercial" else Path(m["path"]).name
        for m in migration_manifest(plane)
    )
    if applied != expected:
        raise SystemExit(
            "Applied migrations do not match the repository. Do not publish a partial schema diagram."
        )
    outputs = {snapshot: json.dumps(catalog, ensure_ascii=False, indent=2) + "\n"}
    for target in PLANE_TARGETS[plane]:
        schema, relative = TARGETS[target]
        if not any(table["schema"] == schema for table in catalog["tables"]):
            raise SystemExit(
                "No "
                + schema
                + " tables in the catalog; confirm the migrations are applied."
            )
        outputs[ROOT / relative] = render(catalog, schema)
    changed: list[str] = []
    for path, content in outputs.items():
        if not path.exists() or path.read_text(encoding="utf-8") != content:
            changed.append(path.relative_to(ROOT).as_posix())
            if not args.check:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8", newline="\n")
    print(
        json.dumps(
            {
                "plane": plane,
                "tables": len(catalog["tables"]),
                "changed": changed,
                "check": args.check,
            }
        )
    )
    if args.check and changed:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
