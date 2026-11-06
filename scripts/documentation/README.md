# Инструменты документации

Скрипты Python используют только стандартную библиотеку. `export_schema.py` отдельно читает
PostgreSQL через psql. Проверка DBML использует закреплённый `@dbml/core`, графики — закреплённый Mermaid CLI. Зависимости Node.js находятся только в этом каталоге и не
входят в промышленные образы PHP.

```powershell
npm ci --prefix scripts/documentation --ignore-scripts
npm run check:dbml --prefix scripts/documentation
npm run check:python --prefix scripts/documentation

# Укажите установленный Chrome/Chromium, без загрузки браузера через npm:
$env:PUPPETEER_EXECUTABLE_PATH='C:/Program Files/Google/Chrome/Application/chrome.exe'
npm run render --prefix scripts/documentation
```

`render` создаёт 13 SVG из `docs/processes/*.mmd`. Для отображения достаточно
открыть SVG в браузере. [Синтаксис Mermaid](https://mermaid.js.org/intro/) и
[Синтаксис DBML](https://dbml.dbdiagram.io/docs/) — первичные описания форматов.

Переопределение версии KaTeX 0.18.2 закрывает уязвимость транзитивной зависимости средства формирования диаграмм;
[сообщение разработчика об уязвимости](https://github.com/advisories/GHSA-238p-pmpm-9mq7) указывает
исправленную версию. `npm audit` проверялся после переопределения версии.

CI проверяет хеши миграций, детерминированные DBML, справочник и покрытие,
реальные объекты парсера и локальные ссылки. Эта проверка сохранённого среза
не заменяет отдельное применение миграций и выгрузку из PostgreSQL.

Русские пояснения к исходным SQL-комментариям находятся в
[словаре перевода](../../docs/database/comment-translations.ru.json). Генераторы
справочника и DBML используют его, сохраняя исходные имена и определения SQL.
JSON-каталоги остаются точными снимками PostgreSQL; их исходные комментарии не меняются.
