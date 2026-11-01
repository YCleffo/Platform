# План развития Platform

Документ разделяет два независимых продукта. Commercial отвечает за каталог, подписки, платежи
и Commercial Account. Education — отдельный будущий продукт; его данные не помещаются в Commercial
и не подменяются фиктивными API.

## Текущий этап: Commercial

Статус: пользовательские recovery-сценарии и общие контролы проверяются CI и одноразовым browser E2E.
Production readiness остаётся `NOT_READY` до закрытия F08/F11/F12 и эксплуатационных проверок.

| Блок | Route | Владелец данных | Фактический API | Критерий приёмки |
| --- | --- | --- | --- | --- |
| Регистрация и вход | `/register`, `/login` | Control User | `/api/control/auth/*` | реальный backend, токены только в памяти |
| Восстановление доступа | `/resend-verification`, `/forgot-password`, `/reset-password?token=…` | Control User | `/api/control/auth/verification/resend`, `/api/control/auth/password/reset/*` | generic acknowledgement, ротация/single-use токена, invalid/used/missing token без пустого экрана |
| MFA и step-up | `/mfa`, `/settings/security` | Control User | `/api/control/auth/mfa/*`, `/api/control/auth/reauthenticate` | enrollment, challenge и step-up покрыты integration/browser тестами; QR имеет accessible name |
| Каталог | `/` | Commercial Catalog | `/api/control/catalog/plans` | опубликованные версии и цены, без mock |
| Кабинет | `/cabinet` | Commercial Account | `/api/control/accounts` | пагинация, создание с Idempotency-Key |
| Участники | `/accounts/:id/members` | Commercial Membership | `/api/control/accounts/:id/memberships` | permissions на сервере, последний owner защищён |
| Приглашения | `/accounts/:id/invitations`, `/invitation` | Commercial Invitation | `/api/control/accounts/:id/invitations`, `/api/control/invitations/*` | email → preview → accept/decline; старый токен после resend отклоняется |
| Sensitive responses | все `/api/control/*` | Control Plane | — | `Cache-Control: no-store`, включая invitation errors |
| Локальные шрифты | все экраны | Frontend | — | Inter и Space Mono из bundle, без Google Fonts |

Ограничение: Commercial UI не является Education dashboard и не должен получать учебные сущности.

### Граница текущего Commercial релиза

В пользовательскую границу входят catalog, аккаунты, участники, приглашения, вход/MFA и
восстановление доступа. Checkout/subscription UI и полный Security management lifecycle
не считаются завершёнными только из-за наличия backend endpoints: их пользовательские
сценарии нужно явно включить в отдельный релизный этап и покрыть browser E2E.

## Следующий этап: Education

Источник: канонический SVG-экран «Сегодня», 393×852. Education routes/API ниже — предложения
для будущего контракта, в приложении их сейчас нет. Реализация начинается только после зелёного
CI и отдельного решения о backend Education. До этого route отсутствует, а данные не имитируются.

| Блок SVG | Предлагаемый route | API: проект, не реализован | Владелец данных | Критерий визуальной проверки |
| --- | --- | --- | --- | --- |
| Приветствие, дата, уведомления, avatar | `/today` | `GET /api/education/today/header` | Education Profile + Notification | приветствие/дата совпадают с локалью, avatar из профиля, badge уведомлений |
| Неделя и выбор даты | `/today` | `GET /api/education/schedule?from=…&to=…` | Education Schedule | выбранный день и диапазон недели совпадают с ответом |
| Следующее занятие и действия «Открыть / Список / Материалы» | `/today` | `GET /api/education/lessons/next`, `GET /api/education/lessons/:id/materials` | Education Lessons | карточка показывает реальное занятие; действия ведут на существующие route |
| Timeline «Сегодня» | `/today` | `GET /api/education/timeline?date=…` | Education Timeline | события отсортированы по времени, пустой день показывает empty state |
| Шесть быстрых действий | `/today` | соответствующие Education endpoints | Education (по действию) | каждая плитка открывает реальный route, без disabled-заглушек |
| «Требует внимания» | `/today` | `GET /api/education/attention` | Education Attention | список соответствует ответу; отсутствие элементов скрывает блок |
| Нижняя навигация «Сегодня / Календарь / Люди / Сообщения / Ещё» | `/today`, `/calendar`, `/people`, `/messages`, `/more` | route-specific APIs | Education Shell | 5 пунктов, active state, высота 72px, safe-area |
| Светлая палитра, радиусы, Inter | все Education routes | — | Frontend design tokens | screenshot 393×852 сравнивается с SVG, шрифты локальные |

### Условия старта Education

1. Зелёный полный CI, включая `browser-e2e`.
2. Создан Education backend с отдельной схемой/владельцем данных и OpenAPI.
3. Для каждого блока выше есть реальный endpoint и тест.
4. В Commercial не появляются Education-таблицы, seed-данные и маршруты.
5. Визуальная приёмка выполняется по screenshots 393×852 и 1440 wide, с RU/EN и обеими темами.

## Ближайшие задачи после исправлений аудита

- Повторить полный GitHub Actions workflow на зафиксированном SHA, включая recovery E2E и
  authenticated accessibility matrix.
- Выполнить production database role provisioning и privilege-denial tests на изолированной копии (F08).
- Обновить и сканировать PostgreSQL/Mailpit images, оформить решение по остаточным CVE (F11/F12).
- Подтвердить, что рабочий Control Plane artifact связан с проверенным SHA, и повторить
  health/login/account smoke после restart.
- Начать Education только по плану выше.
