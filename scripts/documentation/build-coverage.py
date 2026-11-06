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
            or "Нет прямой SQL-строки; смотреть прикладные тесты сценариев"
        )

    lines = [
        "# Покрытие таблиц Core и Runtime",
        "",
        "Генерируется `scripts/documentation/build-coverage.py` из фактического каталога и исходников.",
        "Перечислены все физические таблицы и прямые строки `schema.table` в PHP-коде.",
        "Ссылки показывают место использования, а не автоматически доказанное полное покрытие.",
        "Прикладные тесты могут вызывать репозиторий без SQL-строки в самом тесте.",
        "",
        "Слои проверяются отдельно: модульные тесты — локальная логика; функциональные — приложение с PostgreSQL,",
        "API — HTTP, межсистемные — отдельные процессы Commercial/Core и подписанные документы.",
        "Структура и ограничения описаны в [справочнике БД](database/SCHEMA_REFERENCE.md).",
        "",
        "| Физическая таблица | Прямые ссылки на исходники (до 3) | Прямые ссылки на тесты (до 3) |",
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
        "- Идентичности: Person != Account; Control User и Commercial Account принадлежат другому приложению.",
        "- Пользовательские и сервисные учётные данные не получают универсального публичного редактора.",
        "- Доступ организаций требует членства в нужной области вместе с разрешением.",
        "- Таблицы персональных данных обслуживаются оркестраторами, политиками и реестрами поставщиков сведений.",
        "- Среда установки хранит только локальную идентичность, проверенные документы и состояния обработчиков.",
        "- Внешние ключи SQL не пересекают базы. Исходящая очередь и аудит имеют разный смысл.",
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
