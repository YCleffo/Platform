# Расширение Privacy через contributors

Модуль, владеющий персональными данными, регистрирует свои категории, цели и retention policies через deployment DI. Core не импортирует доменные классы стороннего модуля. Коды уникальны, схемы проверяются при создании registry.

| Интерфейс / регистрация | Обязанность модуля |
| --- | --- |
| `PersonalDataContributor` / `personalDataContributors` | Выгрузить только разрешённые поля конкретного субъекта, в стабильном порядке и пределах лимитов |
| `PersonalDataCorrectionContributor` / `personalDataCorrectionContributors` | Проверить ownership и allowlist полей, вызвать канонический доменный update, вернуть только ID и пути изменённых полей |
| `RetentionCandidateContributor` / `retentionCandidateContributors` | Перечислить ресурсы и все применимые цели с семантическими trigger timestamps |
| `LawfulProcessingBasisContributor` / `lawfulProcessingBasisContributors` | Подтвердить текущее основание обработки; неизвестное основание сохраняет данные |
| `ProcessingRestrictionContributor` / `processingRestrictionContributors` | Идемпотентно применить ограничение по block ID, сохраняя canonical block в Core |
| `PersonalDataErasureHandler` | Выполнить только заявленное действие над собственным resource type и согласовать повтор по item ID |

Contributor не переносит raw payload в audit, outbox, request или execution evidence. Correction участвует в транзакции оркестратора. Внешний side effect erasure должен иметь собственное item-ID reconciliation: транзакция Core не охватывает внешнее хранилище.

Доступность capability следует реальным зарегистрированным путям. Access/export и correction требуют своих contributors. Processing information требует полного покрытия целей и processing-information registry; termination — candidates, restriction contributors и coverage. Общая restriction capability остаётся недоступной без специального пути исполнения.

Erasure проверяется для конкретного субъекта: нужны полное покрытие кандидатов, evidence policy и подходящие handlers. Глобальный флаг без инвентаря субъекта не обещает уничтожение. Core уже регистрирует handlers дочерних ресурсов и anonymization Person/human Account; реализация стороннего модуля не возникает автоматически.

[Privacy Core](PRIVACY_COMPLIANCE_CORE.md) · [Retention и erasure](RETENTION_ERASURE.md)
