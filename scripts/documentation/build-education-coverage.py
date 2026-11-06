"""Inventory every Education table and direct source/test SQL references."""

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
        (ROOT / "docs/database/education/schema.catalog.json").read_text(
            encoding="utf-8"
        )
    )
    source = {
        p: p.read_text(encoding="utf-8")
        for p in (ROOT / "apps/backend/src/Education").rglob("*.php")
    }
    tests = {
        p: p.read_text(encoding="utf-8")
        for p in (ROOT / "apps/backend/tests/Education").rglob("*.php")
        if "_generated" not in p.parts
    }

    def links(paths: Sequence[Path]) -> str:
        return (
            ", ".join(
                "[" + p.stem + "](../" + p.relative_to(ROOT).as_posix() + ")"
                for p in paths[:3]
            )
            or "Нет прямой SQL-строки; смотреть прикладные тесты сценариев"
        )

    tables = [t for t in catalog["tables"] if t["schema"] == "education"]
    lines = [
        "# Покрытие таблиц Education",
        "",
        "Генерируется `scripts/documentation/build-education-coverage.py` из сохранённого",
        "каталога PostgreSQL и исходников Education.",
        "Перечислены все физические таблицы схемы `education` и прямые строки",
        "`education.<table>` в PHP-коде. Ссылки показывают место использования,",
        "а не автоматически доказанное полное покрытие.",
        "Прикладные тесты сценариев могут вызывать репозиторий без SQL-строки в самом тесте.",
        "",
        "| Схема | Таблицы | Столбцы | Внешние ключи | Проверки CHECK | Индексы | Пользовательские триггеры |",
        "| --- | ---: | ---: | ---: | ---: | ---: | ---: |",
        "| `education` | "
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
        + " |",
        "",
        "| Физическая таблица | Прямые ссылки на исходники (до 3) | Прямые ссылки на тесты (до 3) |",
        "| --- | --- | --- |",
    ]
    for table in tables:
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
        "- Education владеет схемой `education`; учётные записи Core, организации и RBAC",
        "  используются через прикладные интерфейсы, а не через внешние ключи между схемами.",
        "- `education.module_migrations` — отдельный журнал необязательного модуля;",
        "  Механизм миграций Core его не изменяет.",
        "- `education.files` хранит метаданные и SHA-256; байты находятся во внешнем",
        "  `FileStore`, поэтому один `pg_dump` не восстанавливает файлы.",
        "- Модуль устанавливается отдельно и включается через жизненный цикл модуля Core,",
        "  права БД и явные назначения преподавателей.",
        "",
        "Точные поля и FK: [справочник](database/SCHEMA_REFERENCE.md).",
        "Каталог и DBML: [база данных](database/README.md).",
        "Границы модуля: [серверная часть Education](architecture/EDUCATION_BACKEND.md).",
        "",
    ]
    output = ROOT / "docs/education-database-coverage.md"
    content = "\n".join(lines)
    if args.check:
        if not output.exists() or output.read_text(encoding="utf-8") != content:
            raise SystemExit("Education coverage inventory drift")
        print("PASS Education coverage inventory")
    else:
        output.write_text(content, encoding="utf-8", newline="\n")
        print("Generated Education coverage for", len(tables), "tables")


if __name__ == "__main__":
    main()
