# Education: backend и установка

Пользователь принял отдельный Yii-модуль `App\Education` со своей PostgreSQL-схемой
`education` и Core identity. Условие старта выполнено до реализации:
[полный CI #22](https://github.com/YCleffo/Platform/actions/runs/42081869693)
для `ea0dc55425395f11fe56c6c2e16b9a9cb8a83445` прошёл все 13 jobs.
Результаты проверки самой реализации фиксируются отдельно в [аудите](../AUDIT.md).

## Границы и владельцы

Core владеет Account, Person, Organization, membership, RBAC и аутентификацией.
Education использует их публичные application-порты. Core domain не зависит от
Education; подключение выполняет composition root. Commercial не содержит учебных
таблиц и не предоставляет identity для Education.

Первый рабочий сценарий — преподаватель. Каждый запрос требует активной Core
identity, свежего permission, активного членства в выбранной организации и явного
назначения преподавателя в Education. Core membership и superadmin сами по себе
не заменяют доменную связь. Группы, ученики, занятия и материалы ограничены
организацией и владельцем; диалог доступен только его участникам. Адресатами первой
версии являются назначенные преподаватели той же организации.

| Данные | Владелец и поведение |
| --- | --- |
| Учебный профиль | Education; собственное отображаемое имя с fallback на Core Person, nullable avatar |
| Группы и ученики | Education; ученик выбирается из активного Core roster организации |
| Занятия и посещаемость | Education; участники фиксируются при создании, новые зачисления добавляются в будущие занятия |
| Расписание и timeline | Проекции реальных занятий и событий, сортировка по instant и ID |
| Требует внимания | Занятия предыдущего локального дня с сохранёнными отсутствиями; пустой список скрывает блок |
| Переписка и уведомления | Education; сообщения двух преподавателей, inbox, монотонный cursor прочтения |
| Материалы и avatar | Education; метаданные в БД, private filesystem adapter вне web root |

Отсутствие организаций, занятия, материалов или событий имеет собственный empty
state. Ошибки не преобразуются в пустые успешные ответы. В runtime нет demo seeds.
Тестовые Core identity/roster создаются только после проверки disposable runtime.

## HTTP и frontend

Фактический контракт: [Education OpenAPI](../../contracts/openapi/education.yaml),
33 операции. Организация передаётся в `X-Education-Organization`, а доступ
перепроверяется сервером. Все личные ответы, включая ошибки авторизации, используют
`Cache-Control: no-store`. Коллекции имеют страницы по 50 записей; расписание —
диапазон до 31 локального дня. Границы дня вычисляются в IANA timezone, включая DST.

Frontend: `/today`, `/calendar`, `/people`, `/messages`, `/more`, формы создания,
детали занятий, посещаемость, материалы и уведомления. Шесть быстрых действий ведут
на работающие workflows. `/lessons/current` явно сообщает об отсутствии текущего
занятия. Неделя, выбранная дата и локаль берутся из backend context. Нижняя навигация
содержит пять пунктов, имеет высоту 72px плюс safe-area. Inter хранится локально.
Канонический светлый экран: [мобайл.svg](../../apps/frontend/design/мобайл.svg), 393×852.

Материалы: PDF, text/plain, PNG, JPEG, WebP, до 10 MiB; avatar — только изображения,
до 2 MiB и 20 млн пикселей. MIME проверяется по содержимому. Выдача требует нового
разрешения, проверяет длину и SHA-256. Имена не задают storage path. Private adapter
заменяется через `FileStore`; для нескольких реплик нужен общий persistent storage.

История сообщений возвращает последнюю страницу по умолчанию, отображая её по
возрастанию времени. Прочтение передаёт последний действительно показанный message ID
и не помечает сообщения, пришедшие позднее. Обновление списка доступно явно.
При смене identity/организации отменяются запросы и очищается UI. Секреты Core
хранятся в памяти; неизвестный результат записи требует сверки списка перед повтором.

## Установка без автоматической выдачи доступа

По умолчанию `EDUCATION_ENABLED=0` и `VITE_EDUCATION_ENABLED=0`: соответствующие
HTTP и frontend routes отсутствуют. Само принятие архитектуры модуль не включает.

1. Установить обычные Core migrations отдельной owner connection. Затем выполнить
   `php yii education:migrate` с той же migration connection. Команда идемпотентна,
   проверяет SHA-256 применённых файлов и регистрирует модуль выключенным.
2. Повторно применить [provision-db-role.sh](../../scripts/operations/provision-db-role.sh)
   с `SCHEMAS=core,platform_runtime,public,education` и прежними отдельными credentials
   owner/runtime/maintenance. Education ledger `education.module_migrations` остаётся
   read-only для runtime. HTTP не должен работать от владельца БД.
3. Задать `EDUCATION_ENABLED=1` в Yii runtime, выполнить `composer yii-config-rebuild`,
   перезапустить runtime. Настроить persistent `EDUCATION_FILE_DIRECTORY` вне web root
   с правами на запись только runtime. Общий PHP ini допускает upload 10M/post 12M;
   reverse proxy должен допускать этот размер.
4. Через существующее Core administration включить модуль `education` и назначить
   конкретному Account роль `education.teacher`. Account должен иметь активный Person
   и membership нужной организации.
5. Отдельно выполнить `php yii education:teacher:assign --actor <admin-account-uuid>
   --account <teacher-account-uuid> --organization <organization-uuid>`.
   Команда проверяет действующего Core администратора с `core.rbac.manage` и целевого
   участника. Назначения всем существующим пользователям нет.
6. Собрать frontend с `VITE_EDUCATION_ENABLED=1`, разместить с действующим Core API
   proxy и открыть `/education/login`. Корневой Commercial catalog `/` сохранён.

Выключение Core module блокирует API немедленно. Для полного удаления маршрутов
выключить оба env-флага и пересобрать/перезапустить соответствующие runtime.

## Проверки и границы выпуска

`tests/Education` выполняется отдельным PHPUnit запуском с `EDUCATION_ENABLED=1`
на защищённой disposable БД. Проверяются scope, свежие permissions, disabled module,
DST, timeline/empty day, реальные файлы, cursor переписки, export/correction.
`scripts/e2e-education.mjs` проходит настоящие Core login, PostgreSQL и private files,
формы, completed download, двух пользователей, RU/EN, light/dark, 393×852/1440 и axe.
CI запускает эти проверки после существующих Core browser workflows.

Privacy export и correction подключены через contributor-порты Core; subject/resource
processing blocks соблюдаются при чтении и записи. Автоматические Education
retention/erasure и legal-hold workflows ещё не реализованы. Их нельзя считать
пройденной частью production приёмки. Публичное размещение, эксплуатация нескольких
реплик и production credentials этим изменением не подтверждаются.
