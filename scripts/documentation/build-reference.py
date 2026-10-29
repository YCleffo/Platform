"""Build the complete column dictionary from exported PostgreSQL metadata."""

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.database.catalog_types import decode_catalog


def cell(value: object) -> str:
    return str(value).replace("|", "&#124;").replace("\n", "<br>")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    catalogs = [
        decode_catalog(
            (ROOT / "docs/database" / p / "schema.catalog.json").read_text(
                encoding="utf-8"
            )
        )
        for p in ["core", "commercial"]
    ]
    lines = [
        "# Справочник фактической базы данных",
        "",
        "Сгенерировано `scripts/documentation/build-reference.py` из `schema.catalog.json`.",
        "Только фактические таблицы, столбцы и SQL FK. Источник структуры — применённые миграции PostgreSQL.",
        "CHECK, partial/expression indexes, trigger functions и deferred semantics полностью сохранены в JSON-каталогах;",
        "DBML служит представлением для диаграммы, а не исполняемой заменой миграций.",
        "",
        "## Инвентарь",
        "",
        "| Схема | Таблицы | Столбцы | FK | CHECK | Индексы | Пользовательские triggers |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
    ]
    for catalog in catalogs:
        for schema in catalog["schemas"]:
            tables = [t for t in catalog["tables"] if t["schema"] == schema]
            lines.append(
                "| `"
                + schema
                + "` | "
                + " | ".join(
                    str(v)
                    for v in [
                        len(tables),
                        sum(len(t["columns"]) for t in tables),
                        sum(c["kind"] == "f" for t in tables for c in t["constraints"]),
                        sum(c["kind"] == "c" for t in tables for c in t["constraints"]),
                        sum(len(t["indexes"]) for t in tables),
                        sum(len(t["triggers"]) for t in tables),
                    ]
                )
                + " |"
            )
    lines += [
        "",
        "Технические `public.platform_migrations` и `public.doctrine_migration_versions` не являются бизнес-таблицами и не включены в инвентарь.",
        "Cross-plane ID в подписанном документе не создаёт FK между двумя базами.",
        "",
        "## Как читать поля",
        "",
        "`NULL: да` означает допустимый SQL NULL. `DEFAULT: —` означает отсутствие SQL default.",
        "`bytea` — хеш, подпись, public key или ciphertext в зависимости от поля.",
        "`timestamp with time zone` хранит момент времени, `amount_minor` — целые минимальные единицы валюты.",
        "SQL-комментарии приводятся из PostgreSQL; отсутствие комментария не заменяется догадкой.",
        "",
    ]
    for catalog in catalogs:
        for table in catalog["tables"]:
            lines += ["## " + table["schema"] + "." + table["name"], ""]
            if table["comment"]:
                lines += [table["comment"], ""]
            lines += [
                "| Поле | PostgreSQL тип | NULL | DEFAULT / generated | Комментарий БД |",
                "| --- | --- | --- | --- | --- |",
            ]
            for c in table["columns"]:
                default = c["default"] if c["default"] is not None else "—"
                if c["identity"]:
                    default = "IDENTITY " + (
                        "ALWAYS" if c["identity"] == "a" else "BY DEFAULT"
                    )
                if c["generated"]:
                    default = "GENERATED " + default
                lines.append(
                    "| `"
                    + c["name"]
                    + "` | `"
                    + c["type"]
                    + "` | "
                    + ("да" if c["nullable"] else "нет")
                    + " | "
                    + cell(default)
                    + " | "
                    + cell(c["comment"] or "—")
                    + " |"
                )
            primary = [
                c["definition"] for c in table["constraints"] if c["kind"] == "p"
            ]
            lines += [
                "",
                "**Первичный ключ:** "
                + ("; ".join(primary) if primary else "отсутствует")
                + ".",
                "",
            ]
            fks = [c for c in table["constraints"] if c["kind"] == "f"]
            if fks:
                lines += ["**Реальные внешние ключи:**", ""]
                lines += [
                    "- `"
                    + c["name"]
                    + "`: `"
                    + c["definition"]
                    + "`"
                    + (
                        " — DEFERRABLE"
                        + (" INITIALLY DEFERRED" if c["initially_deferred"] else "")
                        if c["deferrable"]
                        else ""
                    )
                    + "."
                    for c in fks
                ]
                lines += [""]
            else:
                lines += ["SQL FK из этой таблицы отсутствуют.", ""]
            lines += [
                "CHECK: "
                + str(sum(c["kind"] == "c" for c in table["constraints"]))
                + "; индексов: "
                + str(len(table["indexes"]))
                + "; пользовательских triggers: "
                + str(len(table["triggers"]))
                + ".",
                "",
            ]
    content = "\n".join(lines) + "\n"
    output = ROOT / "docs/database/SCHEMA_REFERENCE.md"
    if args.check:
        if not output.exists() or output.read_text(encoding="utf-8") != content:
            raise SystemExit("Schema reference drift; run build-reference.py")
        print("PASS schema reference")
    else:
        output.write_text(content, encoding="utf-8", newline="\n")
        print("Generated", output.relative_to(ROOT))


if __name__ == "__main__":
    main()
