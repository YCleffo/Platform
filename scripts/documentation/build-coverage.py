"""Inventory every Core table and direct source/test SQL references."""

import argparse
import sys
from collections.abc import Sequence
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

from scripts.database.catalog_types import decode_catalog


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    catalog = decode_catalog(
        (ROOT / "docs/database/core/schema.catalog.json").read_text(encoding="utf-8")
    )
    source = {
        p: p.read_text(encoding="utf-8")
        for p in (ROOT / "apps/backend/src").rglob("*.php")
    }
    tests = {
        p: p.read_text(encoding="utf-8")
        for p in (ROOT / "apps/backend/tests").rglob("*.php")
        if "_generated" not in p.parts
    }

    def links(paths: Sequence[Path]) -> str:
        return (
            ", ".join(
                "[" + p.stem + "](../" + p.relative_to(ROOT).as_posix() + ")"
                for p in paths[:3]
            )
            or "Нет прямой SQL-строки; смотреть application/use-case tests"
        )

    lines = [
        "# Покрытие таблиц Core и Runtime",
        "",
        "Генерируется `scripts/documentation/build-coverage.py` из фактического catalog и исходников.",
        "Перечислены все физические таблицы и прямые строки `schema.table` в PHP-коде.",
        "Ссылки показывают место использования, а не автоматически доказанное полное покрытие.",
        "Application tests могут вызывать repository без SQL-строки в самом тесте.",
        "",
        "Слои проверяются отдельно: unit — локальная логика, functional — приложение с PostgreSQL,",
        "API — HTTP, cross-plane — отдельные Commercial/Core процессы и подписанные documents.",
        "Структура и ограничения описаны в [справочнике БД](database/SCHEMA_REFERENCE.md).",
        "",
        "| Физическая таблица | Прямые source references (до 3) | Прямые test references (до 3) |",
        "| --- | --- | --- |",
    ]
    for table in catalog["tables"]:
        name = table["schema"] + "." + table["name"]
        owners = sorted(
            (p for p, text in source.items() if name in text),
            key=lambda p: p.relative_to(ROOT).as_posix(),
        )
        checks = sorted(
            (p for p, text in tests.items() if name in text),
            key=lambda p: p.relative_to(ROOT).as_posix(),
        )
        lines.append(
            "| `" + name + "` | " + links(owners) + " | " + links(checks) + " |"
        )
    lines += [
        "",
        "## Границы владельцев",
        "",
        "- Identity: Person != Account; Control User и Commercial Account принадлежат другому приложению.",
        "- Auth credentials и service credentials не получают универсальный публичный CRUD.",
        "- Organization access требует membership scope вместе с permission.",
        "- Privacy tables обслуживаются оркестраторами, policy и contributor registries.",
        "- Runtime хранит только локальную identity, проверенные документы и worker status.",
        "- SQL FK не пересекает границы двух баз. Outbox и audit имеют разную семантику.",
        "",
        "Точные поля и FK: [справочник](database/SCHEMA_REFERENCE.md). Процессы: [графики](processes/README.md).",
        "",
    ]
    output = ROOT / "docs/core-database-coverage.md"
    content = "\n".join(lines)
    if args.check:
        if output.read_text(encoding="utf-8") != content:
            raise SystemExit("Coverage inventory drift")
        print("PASS coverage inventory")
    else:
        output.write_text(content, encoding="utf-8", newline="\n")
        print("Generated coverage for", len(catalog["tables"]), "tables")


if __name__ == "__main__":
    main()
