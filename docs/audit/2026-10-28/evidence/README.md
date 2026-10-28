# Evidence аудита 2026-10-28

[Checks](checks.json) — результаты и явно не выполненные gates.
[Source manifest](source-manifest.json) — SHA-256 1233 source/config/test/tool files.
[Итоги нагрузки](load-results.json) и [HTTP customer smoke](http-business-smoke.json).

Для каждого load profile сохранены `.summary.json` и `.resources.json`.
Итоговый 10 000 VU файл: [metrics](final-online-10000.summary.json),
[ресурсы, параметры и image IDs](final-online-10000.resources.json).
[Максимумы samples](resource-peaks.json) не считаются одновременными.

Rate metrics `passes` означают события со значением true: у failure rate это ошибки.
Для оценки используйте `value` и `exit_code`; threshold=true в этом k6 summary
означает пересечённый порог. Код 99 не является PASS.

Все учётные записи нагрузочного стенда синтетические. Raw tokens, passwords,
письма и полноценные Codeception diagnostics в этот каталог не копируются.
