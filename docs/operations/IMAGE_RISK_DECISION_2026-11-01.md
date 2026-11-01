# Остаточный риск app images — 2026-11-01

Статус: **NOT_ACCEPTED / release blocker**. Документ фиксирует оценку F12 из
[аудита](DEEP_AUDIT_2026-11-01.md), а не объявляет уязвимости отсутствующими.

## Наблюдение

Свежие Core и Commercial production images содержат `CVE-2026-6653` (libxml2, CRITICAL)
и 60 HIGH без известного исправления в актуальной базе сканера. Число HIGH менялось
между прогонами без изменения source из-за обновления vulnerability DB. Полные inventories:
`evidence/deep-audit-2026-10-31/trivy-core.json` и `trivy-commercial.json`.

## Оценка достижимости

- Поиск по `apps/backend` и `apps/control-plane` не нашёл вызовов `DOMDocument`,
  `SimpleXMLElement`, `simplexml_load_*`, `xml_parse_*` или `libxml_*` в прикладном коде.
- Core image устанавливает расширения `xml`, `xmlwriter`, `simplexml`; наличие расширения
  само по себе не доказывает достижимость уязвимой функции из HTTP entrypoint.
- Production config использует PHP/YAML конфигурацию; пользовательские XML upload/parse
  сценарии в текущем Commercial UI и OpenAPI не найдены.
- Это статическая оценка, не exploit PoC. Транзитивные библиотеки и будущие endpoints
  могут изменить вывод; scanner inventory не является доказательством exploitability.

## Решение и условия выпуска

1. Не использовать `ignore-unfixed` как утверждение «уязвимостей нет»; inventory и
   fixable gate публикуются отдельно.
2. До production получить от security owner одно из:
   - совместимое исправление base image/package с regression evidence; либо
   - письменное принятие остаточного риска с compensating controls, владельцем и датой пересмотра.
3. Компенсирующие меры до решения: не принимать XML от пользователей, ограничить content types
   на ingress, сохранить WAF/ingress-фильтрацию и мониторинг, повторить Trivy после обновления DB.
4. Владелец решения: security lead платформы; release manager не может закрыть F12 самостоятельно.

## Воспроизведение

```bash
trivy image --scanners vuln --severity HIGH,CRITICAL --format json \
  --output trivy-core.json platform-core:ci
trivy image --scanners vuln --severity HIGH,CRITICAL --format json \
  --output trivy-commercial.json platform-control-plane:ci
```

Gate с `ignore-unfixed: true` проверяет только исправляемые находки; полный inventory
хранится как CI artifact и должен прилагаться к release decision.
