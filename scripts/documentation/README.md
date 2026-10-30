# Документационные tools

Python scripts используют только stdlib. `export_schema.py` отдельно читает
PostgreSQL через psql. Проверка DBML использует pinned `@dbml/core`, графики —
pinned Mermaid CLI. Node dependencies находятся только в этом каталоге и не
входят в PHP production images.

```powershell
npm ci --prefix scripts/documentation --ignore-scripts
npm run check:dbml --prefix scripts/documentation
npm run check:python --prefix scripts/documentation

# Укажите установленный Chrome/Chromium, без загрузки браузера через npm:
$env:PUPPETEER_EXECUTABLE_PATH='C:/Program Files/Google/Chrome/Application/chrome.exe'
npm run render --prefix scripts/documentation
```

`render` создаёт 13 SVG из `docs/processes/*.mmd`. Для отображения достаточно
открыть SVG в браузере. [Mermaid syntax](https://mermaid.js.org/intro/) и
[DBML syntax](https://dbml.dbdiagram.io/docs/) — первичные описания форматов.

Override KaTeX 0.18.2 закрывает advisory транзитивного renderer dependency;
[upstream advisory](https://github.com/advisories/GHSA-238p-pmpm-9mq7) указывает
исправленную версию. `npm audit` проверялся после установки override.

CI проверяет hashes миграций, детерминированные DBML/reference/coverage,
реальные parser objects и локальные ссылки. Эта проверка сохранённого среза
не заменяет отдельное применение миграций и export из PostgreSQL.
