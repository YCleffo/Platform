# Frontend Platform

SPA на Vue 3, TypeScript strict и Vite. Использует раздельные Core и Commercial API по
[контрактам](../../contracts/openapi/). Токены авторизации живут только
в памяти; persistent storage используется для темы, языка и внешнего вида. После перезагрузки нужен вход.

## Границы приложения

| Слой | Ответственность |
| --- | --- |
| `app` | Router, layouts, theme, appearance preferences, сборка приложения |
| `core` | Раздельные Commercial auth/session и Core admin session, account context, композиция HTTP-клиентов |
| `modules/commercial` | auth, onboarding, catalog, account, security, invitations, billing, usage, analytics, audit |
| `modules/admin` | Core admin pages, epoch-scoped cache и операции, отдельные login/recovery/callback views |
| `modules/settings` | Настройки внешнего вида, общий layout настроек и примеры компонентов |
| `shared` | HTTP transport/guards, UI, i18n, formatting, validation, local assets |

`shared` не импортирует `core` или `modules`; UI не выполняет HTTP-запросы.
`core` не зависит от страниц. Модули используют публичные контракты `core/shared`.
Core Account, Control User и Commercial Account остаются разными identities.
Education dashboard из SVG — будущий модуль; его API ещё не реализован.

## Реальные маршруты и сценарии

| Route | Сценарий |
| --- | --- |
| `/` | Опубликованный Commercial catalog |
| `/register`, `/verify-email`, `/login`, `/mfa` | Регистрация, подтверждение и вход |
| `/resend-verification` | Повторная отправка письма подтверждения по email без повторной регистрации |
| `/forgot-password`, `/reset-password?token=…` | Запрос и подтверждение сброса пароля |
| `/does-not-exist` и другие неизвестные URL | Локализованная 404-страница с возвратом к каталогу |
| `/cabinet` | Список и создание Commercial Accounts |
| `/accounts/:accountId` | Overview, сведения и текущие permissions |
| `/accounts/:accountId/members` | Список, изменение ролей, removal; защита последнего owner |
| `/accounts/:accountId/invitations` | Создание, список, resend, revoke |
| `/accounts/:accountId/billing` | Подписка, смена тарифа, billing profile, invoices/payments/receipts/refunds, payment methods, checkout |
| `/accounts/:accountId/usage` | Effective entitlements и usage counters текущего периода |
| `/accounts/:accountId/analytics` | KPI аккаунта, billing, audit activity, top actions и usage-графики за 7/30/90 дней |
| `/accounts/:accountId/audit` | Owner/admin audit trail с фильтрами, cursor pagination и metadata |
| `/invitation` | Публичный preview; accept/decline после входа |
| `/settings/security` | MFA enrollment, recovery codes, password change, все sessions и revoke, step-up |
| `/settings/privacy` | Серверный экспорт персональных данных пользователя |
| `/settings` | Тема, язык, размеры, отступы, анимации и тени; доступно без входа |
| `/settings/components` | Рабочие примеры общих dropdown и контекстного меню |
| `/admin/login` | Отдельный вход в Platform Core admin console; customer-токены не используются |
| `/admin/security`, `/admin/security/mfa`, `/admin/security/sessions` | Core пароль, MFA enrollment/disable/recovery codes и собственные device sessions/revoke; отдельный typed adapter, без Commercial auth store |
| `/activate`, `/password-reset`, `/mfa-recovery` | Одноразовые Core ссылки из SMTP: активация, пароль и восстановление MFA |
| `/admin/forgot-password`, `/admin/mfa-recovery`, `/auth/callback/:provider` | Запрос Core reset/recovery и внешний Google/Telegram callback |
| `/admin`, `/admin/organizations`, `/admin/persons`, `/admin/accounts`, `/admin/rbac`, `/admin/modules`, `/admin/settings`, `/admin/privacy`, `/admin/audit` | Core admin-плоскость: CRUD/lifecycle, RBAC, настройки, privacy operations и аудит |
| `/admin/profile/privacy`, `/admin/profile/privacy/:id` | Core self-service: собственные и представительские запросы, статусы/сроки, одноразовый ответ и JSON download |
| `/admin/privacy/requests/:id` | Операторская карточка запроса: проверка доказательств, исправления, сроки, ограничения и прекращение обработки; выдача только для офлайн-поступления |
| `/admin/finance`, `/operator` | Отдельная Commercial operator session: privacy intake/resolve, incidents и внешние compensation attestations с MFA и независимым вторым оператором |

На мобильном (<768 px) основная навигация дополнена полноэкранным меню: focus trap,
Escape, блокировка scroll, safe-area, крупные ссылки, account-разделы, тема/язык и вход/выход.
Меню закрывается при переходе и возвращает фокус на trigger.

Admin console имеет отдельный Core auth store и guard: `/api/auth/login`, MFA,
`/api/auth/me` permissions. Admin routes лениво загружаются отдельными chunks. Подробнее:
[ADMIN_CONSOLE](../operations/ADMIN_CONSOLE.md).

Admin cache, ожидающие запросы и операции привязаны к session epoch. Logout/смена identity
сбрасывают cache; запоздавшие HTTP/refresh/decoration/dialog результаты не попадают в новую
сессию. External recovery хранит hash state, provider, purpose, expiry и несекретный client ID
в одноразовом sessionStorage marker. После проверки state callback восстанавливает исходный
client ID для серверной привязки login/recovery, удаляет marker и code/state из URL.
Новая обычная страница получает новый ID. Test OAuth transport включается только
в guarded Core test runtime; живые Google/Telegram требуют отдельной проверки credentials/redirects.

Billing восстанавливает profile, draft/pending документы, историю и открытый plan change из
`/accounts/:accountId/billing-state`. Запросы привязаны к сессии, account и поколению операции.
Начальная неоплаченная подписка может не иметь service period: UI показывает прочерк.
Отмена auto-renew сохраняет доступ до конца периода; немедленная отмена завершает доступ,
а refund оформляется отдельно. Проверки и незакрытые границы — в [едином аудите](../AUDIT.md).

Invitation token захватывается из `#token=…`, удаляется из адресной строки и хранится
в памяти. Reset token приходит в `?token=…`, не пишется в storage и удаляется из URL
после успешной смены пароля. Публичные ответы запроса reset и resend не раскрывают,
существует ли аккаунт. Preview не меняет данные. Account permissions вычисляет сервер
и повторно проверяет при мутациях; UI скрывает недоступные действия.

Session epoch и request IDs отбрасывают устаревшие ответы после logout/смены аккаунта.
HTTP 401 вызывает один single-flight refresh и максимум один retry. Просроченная
сессия и временная ошибка сети обрабатываются раздельно. Sensitive mutations требуют
MFA/recent authentication; `useSensitiveAction` повторяет действие после step-up.

## Дизайн и локальные ресурсы

Источники: [design-tokens.json](../../apps/frontend/design/design-tokens.json)
и [мобильный SVG](../../apps/frontend/design/мобайл.svg).
`generate:tokens` переносит цвета, typography, spacing/gap/padding/radius/size;
`theme.css` сопоставляет их семантическим переменным Light/Dark/System.

**Иконки:** выбранные Solar Linear SVG находятся в `src/shared/ui/icons/svg/`.
`npm run generate:icons` создаёт небольшой `sprite.svg`; `AppIcon` использует
`<use href="local-sprite.svg#symbol">`. Named exports сохраняют типизированные
компоненты и dynamic component props. В production Vite выпускает спрайт с hash в имени.
Полный Solar pack сохранён в `src/shared/Solar icons/` как библиотека для будущих экранов;
он не импортируется целиком и не входит в production bundle. Зависимость Lucide удалена.
`check:icons` проверяет соответствие
спрайта исходникам; в CI это обязательный gate. Иконки используют `currentColor`,
декоративные SVG скрыты от screen reader, доступное имя задаёт button/link.

Новая иконка выбирается из полной библиотеки и добавляется проверенным SVG 24×24 в `svg/`, затем обновляется
спрайт и typed export. Не загружать icon CDN и не включать весь каталог в bundle.
Сложную многоцветную иллюстрацию держать отдельным asset; UI-пиктограммы переиспользовать
через спрайт. Поведение ссылок на symbol описано в
[MDN](https://developer.mozilla.org/en-US/docs/Web/SVG/Reference/Element/use).

В будущем переключение Linear/Bold организуется через карту вариантов внутри `AppIcon`;
экраны продолжают использовать семантические имена. Полную библиотеку не нужно загружать
в браузер ради такого переключения. Спрайт остаётся заменяемой деталью рендера.

**Шрифты:** `fonts.css` подключает local Inter variable 100–900 и Space Mono 400/700
в WOFF2. Исходные TTF/static/Roboto сохранены как библиотека; в сборку они не импортируются.
Inter — основная typography,
Space Mono — коды/идентификаторы. Google Fonts и внешних font links нет.
При сборке сохраняются [third-party notices](../../apps/frontend/public/third-party-notices.txt)
и полные OFL в `public/licenses`.

**Типографика:** `base.css` задаёт body-medium (14/20 px при 100%), `typography.css` —
общие H1/H2/H3, labels/legend, small и mono. Desktop заголовки: 24/32, 20/28, 18/24 px,
weight 600; mobile: 22/28, 18/24, 16/20. Страница выбирает роль через `--type-*`, включая
size, line, weight и tracking. Нельзя полагаться на browser heading defaults или задавать
кегль без своего line-height/weight. Подпись medium отличается от metadata small (12/16),
а input L намеренно крупнее M (16/24 против 14/20). Масштаб текста меняет размер и строку
вместе; generated tokens вручную не редактировать. Layout class/style у `AppInput`
относятся к wrapper, native attributes и listeners — к самому input.

## Настройки внешнего вида и анимации

`app/appearance` владеет валидируемыми настройками и применяет их к корневым CSS-переменным.
`platform.appearance.v1` хранит только UI-предпочтения. Изменения применяются сразу,
синхронизируются между вкладками и переживают перезагрузку. При запрете storage
интерфейс работает в пределах текущей вкладки и сообщает, что сохранение недоступно.
Сброс внешнего вида сохраняет выбранные язык и тему.
Готовые варианты Balanced/Compact/Easy reading меняют только размеры и отступы,
сохраняя эффекты и тему. Отмена хранит до 20 изменений размеров/эффектов в памяти вкладки;
непрерывный drag — одно действие. Сброс оформления и отдельного ползунка можно отменить.
При изменении в другой вкладке старая история отмены сбрасывается. Ползунки имеют
кнопки точного шага и индивидуальный сброс; редкие параметры собраны в раскрываемом блоке.

| Настройка | Допустимые значения |
| --- | --- |
| Плотность | compact / comfortable / spacious; коэффициенты 0,85 / 1 / 1,15 |
| Масштаб шрифта | 90–140%; меняются размер и line-height |
| Отступы | 75–125% поверх плотности |
| Кнопки | Горизонтальный padding 8–32 px, вертикальный 6–16 px; без обрезания текста фиксированной высотой |
| Скругления | 0–150% |
| Тени | none / subtle / normal / strong; focus ring сохраняется |
| Анимации | Вкл./выкл., скорость 0,5–2×, смена темы 0–600 ms |

Исходный JSON дизайна не меняется: генератор CSS выражает размеры через масштабируемые
переменные. Новые компоненты используют эти же токены. На touch-устройствах высота
интерактивных контролов остаётся не меньше 44 px.

`shared/styles/appearance.css` задаёт общие `--motion-*-duration`, easing и
`--theme-transition`. Цвета компонентов не получают собственные длительности.
При смене темы View Transitions одновременно смешивает старый и новый снимки страницы;
вложенные CSS color transitions на это время отключены. Повторный быстрый выбор отменяет
предыдущий переход, побеждает последний выбор. Без View Transitions тема применяется
атомарно. Системный reduced motion имеет приоритет над пользовательским включением.
Механизмы описаны в [MDN View Transitions](https://developer.mozilla.org/en-US/docs/Web/API/Document/startViewTransition)
и [MDN reduced motion](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/At-rules/@media/prefers-reduced-motion).

## Dropdown и контекстное меню

Общая механика находится в `shared/ui/menus/AppMenu.vue`, без HTTP и маршрутизации.
`MenuItem` задаёт уникальный `id`, текст, optional description/icon/shortcut,
disabled, radio/checkbox состояние, children и kind item/group/separator.
Событие `select(item)` передаёт действие владельцу меню. IDs уникальны во всём дереве;
подписи выводятся как текст. `searchable` ищет листья по всей иерархии с путём к разделу.
Disabled родителя наследуется потомками.

`shared/ui/fields/AppDropdown.vue` принимает `options: DropdownOption[]`, `label`,
`v-model`, optional id/searchable/multiple/clearable/disabled/invalid/placeholder.
`searchable` по умолчанию `auto`: поле поиска появляется только для длинных списков
(больше 8 листьев), поэтому короткие выборы языка/темы/роли остаются компактными;
`searchable` без значения или `true` принудительно включает поиск.
Одиночная модель — строка, множественная — массив строк. Родитель с children открывает
раздел, выбирается конечный пункт. Multiple оставляет меню открытым и переключает
checkbox-состояния; clearable сбрасывает выбор. В multiple-trigger показываются не более
двух chips и `+N`, поэтому длинный выбор не переносится на вторую строку; полный список
доступен в `title` и accessible name. Внешний `<label for>` связан с id кнопки,
а обязательный `label` даёт ей доступное имя даже без внешней подписи.
Компонент эмитит модель; сериализация формы и серверная валидация остаются у вызывающего кода.

```vue
<AppDropdown v-model="language" label="Язык" :options="languages" />
<AppDropdown v-model="selected" label="Разделы" :options="tree" multiple searchable clearable />
<AppContextMenu :items="actions" label="Действия" @select="handleAction">
  <section tabindex="0">Область контекстного меню</section>
</AppContextMenu>
```

Десктоп показывает соседние каскадные панели; у края они разворачиваются и ограничиваются
viewport. Если цепочка не помещается или ширина меньше 640 px, показывается один уровень
с кнопкой «Назад». Поиск работает и из вложенного уровня, не теряя фокус при смене панели.
Enter/Space выбирают, Up/Down/Home/End перемещают фокус, Right открывает раздел,
Left и Escape возвращают назад. Escape в корне закрывает меню и возвращает фокус;
Tab закрывает его и продолжает обычный порядок формы. Disabled доступны фокусу, но не
активируются; структура и состояния используют ARIA menu/menuitemradio/menuitemcheckbox.
Основа клавиатурного поведения: [WAI-ARIA menu](https://www.w3.org/WAI/ARIA/apg/patterns/menubar/).

В диалоге меню портируется внутрь modal scope, поэтому первый Escape закрывает меню,
следующий — диалог. Outside pointer закрывает меню без перехвата фокуса у новой цели.
Слоты footer предназначены для пояснения списка.

`AppContextMenu` открывается по contextmenu или Shift+F10/ContextMenu на фокусируемой
области. Поля, contenteditable, ссылки, кнопки, выделенный текст и Shift+right click
сохраняют браузерное меню. Атрибут `data-native-context` исключает произвольную область.
`app/layouts/SiteContextMenu.vue` добавляет действия страницы: переходы, тему и плотность.
Все четыре прежних native select заменены: язык, тени, роль участника и роль приглашения.
При открытии списка без поиска фокус переходит к выбранному пункту; стрелки на кнопке
по-прежнему открывают первый/последний вариант. Очистка поиска сохраняет фокус в поле.
Внешний `<label for>` не считается outside-click: клик по нему переключает dropdown один
раз, а не закрывает и сразу переоткрывает меню с повторной анимацией.

## Проверки и запуск

Команды: [README приложения](../../apps/frontend/README.md).
Vite proxy `/api/control` направляет в Commercial, `/api` — в Core; адреса задаются
`VITE_COMMERCIAL_PROXY_TARGET` и `VITE_CORE_PROXY_TARGET`, не компонентами.

Vitest выполняет unit/component tests без БД. `verify-responsive.mjs` проверяет
публичные и guard-состояния на 320–1440px, темы, keyboard, RU и overflow.
Это не подтверждение аутентифицированных сценариев. `verify-appearance.mjs` проверяет
переменные, сохранение, reduced motion, синхронность темы и размеры. `verify-menus.mjs`
проверяет поиск, keyboard/focus, каскад, мобильный Back, края экрана, modal scope и context menu.
Проверки внешнего вида выполняются на production preview; devtools-зависимости dev-сборки
сами обращаются к localStorage и мешают корректной проверке полностью запрещённого storage.
`check:styles` блокирует неопределённые CSS-переменные, локальные animation timings
и transitions без общей цветовой части. Все проверки включены в CI.

`npm run verify:typography` проверяет computed size/line-height/weight/family и overflow
всех named routes на production preview: 49 маршрутов, 400 состояний, 320–1440 px,
100/140% текста, RU/EN и обе темы. Список проверяется по AST router. Используются безопасные
HTTP fixtures, включая пустые/error состояния; это проверка стилей, не backend E2E.
Снимки и JSON создаются в ignored `screenshots/`.

`verify-ux.mjs` проверяет undo/presets/точные шаги и выполняет 30 циклов меню с 10 диалогами;
число глобальных overlay listeners должно вернуться к исходному. Замер полного цикла
Playwright включает автоматизацию и не является метрикой input latency.
`verify-accessibility.mjs` использует axe-core для public/auth/settings/cascade/modal/mobile
в обеих темах, без отключения правил A/AA; authenticated state сканируется в browser E2E
(включая QR настройки MFA). Это дополняет проверку клавиатуры и скриншотов, но не доказывает
полное соответствие WCAG: [границы автоматической проверки](https://playwright.dev/docs/accessibility-testing).

`load-commercial.mjs` выполняет nonce-проверку одноразового окружения **до** создания fixtures.
Профили и команды описаны в README приложения. Чередуются четыре GET endpoint с валидацией
JSON: опубликованный каталог с 3 synthetic plans/prices/features, текущий пользователь,
непустой список accounts и активная сессия. Запросы используют одного пользователя/сессию;
workers ограничены профилем. Отдельно учитываются ошибки транспорта, статуса и контракта;
автоматических retries нет. Пустой или слишком короткий этап не проходит. CI использует
smoke, локальная проверка может использовать standard/stress. Показатели не переносятся
на production без репрезентативных данных, окружения и согласованного SLO.

Полный CI browser job запускает отдельные PostgreSQL/Commercial/Mailpit, outbox worker,
Vite и Chromium. `e2e-account-workspace.mjs` и `e2e-invitation-lifecycle.mjs` выполняют
реальные account, billing, usage, analytics, audit, privacy export и invitation/MFA/membership
flows; authenticated accessibility matrix включает Security Center, MFA setup, recovery codes
и MFA challenge. Полноэкранное mobile menu проверяется в `verify-menus.mjs` на 393 px:
размер viewport, scroll lock, Escape, focus return и закрытие после навигации.
`e2e-auth-recovery.mjs`
проверяет standalone resend после reload, ротацию verification token, реальное письмо
reset, подтверждение в новой вкладке, single-use/invalid/missing token и гигиену URL.
Guard требует isolation nonce, подтверждённый сервером. Перед recovery и load CI
сбрасывает одноразовую Commercial schema, чтобы лимиты invitation flow не давали 429.
Vite proxy использует явный `http.Agent({ keepAlive: false })` без заголовка
`Connection: close` (он давал ECONNRESET на быстром Core). Transport-level proxy errors
возвращаются как 502; клиент повторяет **только один idempotent GET** после network/502
failure. HTTP 5xx backend и POST не повторяются. Рабочие БД для этого не используются;
после job ресурсы удаляются.

### Checkbox, switch и range

`AppCheckbox` и `AppSwitch` находятся в `shared/ui/fields`, принимают boolean `v-model`,
обязательные `id`/`label`, `description`, `disabled` и `name`. Checkbox дополнительно
поддерживает `indeterminate`, `required` и `value`. Это настоящие HTML inputs: сохраняются
Space/Tab, label click, disabled и участие в формах. `indeterminate` управляется отдельно
от значения. Подсказка и внешний `aria-describedby` объединяются; имя берётся из label.
`AppSortableList` — перетаскиваемый список строк (`v-model: string[]`). Ручка drag, нативный
HTML5 drag-and-drop, клавиатурная альтернатива Alt+Arrow Up/Down, aria-label с позицией.
`AppInput`, `AppCheckbox` и `AppSwitch` переносят нативные form-атрибуты (`name`,
`form`, `required`, `autocomplete` и другие) на реальный control, а не на wrapper.
Обычный `form.reset()` после завершения события синхронизирует модель с фактическим
состоянием DOM, а `@reset.prevent` оставляет контрол, модель и `FormData` без изменений.
Disabled controls не отправляются.

Switch применяется для немедленного изменения настройки (например, анимаций), checkbox —
для выбора пунктов. Примеры с общим/частичным выбором и disabled доступны на
`/settings/components`; checkbox также есть в живом preview настроек.

`AppRange` сохраняет нативное управление мышью, touch и клавиатурой. Заполнение дорожки
обновляется без задержки, бегунок растёт при удержании; отпускание снаружи, отмена touch,
blur и отключение завершают редактирование. Drag объединяется в один шаг Undo.
Цвета используют `--theme-transition`, геометрия — `--motion-control-duration` и
`--motion-spring-ease`; скорость и reduced motion наследуются из настроек сайта.

`BASE_URL=http://127.0.0.1:5173 npm run verify:controls` проверяет 30 browser assertions:
mouse/touch/keyboard, mixed/disabled, native form attributes/reset (обычный и отменённый),
release outside, Undo, reload, reduced motion и 320 px / 140% text. Скрипт включён в browser CI; screenshots
пишутся в игнорируемую папку `screenshots`; несколько текущих снимков доступны
из [аудита](../AUDIT.md).

Результаты и production-ограничения: [критерии выпуска](../operations/READINESS.md).
Дальнейшие модули: [единый roadmap](../roadmap/PLATFORM_ROADMAP.md).

### Собственные настройки и согласия Core

`/admin/profile` и `/admin/profile/consents` находятся в `CoreProfileLayout`; это self-service
для любой Core session, без прав управления другими аккаунтами. Typed adapter —
`shared/api/core/account.ts`, контракт — `contracts/openapi/account-settings.yaml`.
Preferences PATCH передаёт только изменённые locale/timezone, сервер сохраняет пропущенное
поле атомарно. Settings schema v2 хранит sparse overrides; null сбрасывает только известный
leaf. Форма не отправляет module namespaces и не удаляет чужие overrides.

`core/admin/accountSettingsStore.ts` хранит настройки только в памяти, сбрасывается синхронно
по session epoch и отвергает устаревшие ответы. `AdminLayout` применяет сохранённую policy
анимации/прозрачности через appearance API, при выходе из Core восстанавливает браузерную
policy. Reduced motion пользователя/ОС имеет приоритет. Переключатели формы применяются
после явного сохранения. Notification flags сохраняют предпочтения каналов; браузерное
разрешение они не запрашивают.

Согласия используют plaintext document title/text, привязанные на сервере к SHA256 документа.
POST передаёт просмотренные version/hash; 409 требует повторного просмотра. Необязательные
документы без текста и пустой реестр показывают заменяемые RU/EN шаблоны из
`modules/admin/profile/consentTemplates.ts`; шаблонные просмотры не создают ложную запись
согласия. Опубликованный документ имеет явный unchecked checkbox и подтверждение, отзыв —
отдельный диалог с повторной попыткой. Действующий реестр задаётся конфигурацией Core.

Проверки: `verify:core-profile` — HTTP fixtures, responsive/axe; `test:e2e:core-profile` —
реальный одноразовый Core/PG/Redis с проверкой durable state, logout/login и concurrent PATCH.

### Passkeys и внешние аккаунты Core

`AdminPasskeysView` и `AdminIdentitiesView` находятся во вкладках `/admin/security` и доступны
любой Core session. Typed DTO и методы — `shared/api/core/credentials.ts`; browser boundary —
`shared/webauthn.ts`. WebAuthn options переводятся из base64url в ArrayBuffer, attestation/assertion
обратно в JSON. Требования сервера к UV/resident key не ослабляются. Сериализация и отмена
сверены с [WebAuthn Level 3](https://www.w3.org/TR/webauthn-3/). Backend проверяет RP/origin,
challenge/session/client binding, подпись и counter; private key остаётся у аутентификатора.

Passkey login использует общий `adminAuthStore.accept`, поэтому обязательные MFA/password change
продолжаются теми же формами. Native prompt получает AbortSignal текущей auth operation;
выход, отмена, уход со страницы и новая попытка не принимают поздний результат.
Создание/удаление требуют recent authentication; 409 последнего способа входа не превращается
в успешное удаление. Переименование допускает null label, формы ограничены 160 символами.

Привязка внешнего аккаунта реализована в `auth/externalLink.ts`: popup открывается сразу по клику,
его opener обнуляется, в его sessionStorage записывается одноразовый OAuth marker:
hash state/provider/expiry/purpose/client ID.
В Core session storage не попадают токены или код. Callback очищает URL, одноразово потребляет
marker и передаёт код через BroadcastChannel с именем из SHA256 state. Popup не восстанавливает
client ID исходной вкладки: API завершения link вызывает сама исходная вкладка. Она
принимает только совпадающие provider/state и вызывает authenticated link callback под своей
Core session. При отмене/unmount/смене epoch закрываются канал/окно, late callback не выполняет
новую mutation. Предусмотрены popup blocker, timeout, provider cancel, ownership conflict,
неполный discovery и последняя доступная identity. Session rotation в том же session ID сохраняется.

На узком экране вкладки прокручиваются горизонтально, активная остаётся видимой без вертикального
скачка страницы. Формы используют существующие dialogs/buttons/input и общие CSS tokens.
RU/EN сообщения ошибок и успеха переводятся реактивно. Scenarios включены в локальный/GitHub CI:
`verify:core-credentials` — fixtures/axe/responsive/native virtual authenticator;
`test:e2e:core-credentials` — реальный guarded backend с virtual authenticator и synthetic OIDC
transport, PostgreSQL assertions, новый вход и 100 concurrent authenticated read requests.

## Core self-service privacy

`/admin/profile/privacy` и `/admin/profile/privacy/:id` доступны human Core account без
операторских permissions. [privacy.ts](../../apps/frontend/src/shared/api/core/privacy.ts)
содержит DTO guards, 8 request types и выбор self/representative delivery path.
[privacyRequestsStore.ts](../../apps/frontend/src/core/admin/privacyRequestsStore.ts) хранит
только metadata, по 25 строк + sentinel. Session epoch/request generation отбрасывают
поздние результаты; ошибка следующей страницы сохраняет строки и тот же offset для retry.

Форма выбирает себя либо полномочие из authenticated safe discovery. Backend отдаёт только
verified/current/non-revoked authority с privacy scopes; proof/evidence и общий person lookup
в клиент не попадают. Варианты запроса зависят от scopes. Erasure/processing termination
требуют acknowledgement. POST не повторяется автоматически; при uncertain response форма
просит сначала проверить список. Submit-only grant подтверждает приём без обещания чтения.

Статусы, сроки и overdue берутся с сервера. Выдача данных/processing-information выполняется
только в `in_progress`, перепроверяет authority и завершает request. Это одноразовый stateful
GET: `coreAdminHttp.document` сохраняет raw text и запрещает transient replay. 401 refresh
остаётся общим. Метаданные/POST ограничены 15 секундами ожидания, выдача — 60 секундами;
таймаут не создаёт второй POST/выдачу. Permission hint не показывает отказ, пока loading не завершён.

Глобальный `AppJsonResponseDialog` принимает optional description/actions slot, исходные
потребители сохранены. Preview форматируется, JSON download использует точные исходные байты.
Subject/schema валидируются до показа. Документ находится только в component memory,
сохраняется при ошибке metadata refresh, очищается при смене страницы/logout. Blob URL
освобождается. После reload завершённого запроса новый экземпляр ответа не обещается.

`verify:core-privacy` — HTTP fixture UI, responsive/axe, scopes/errors/retry/uncertain/timeout;
`test:e2e:core-privacy` — реальный guarded Core/PG/Redis, четыре self/representative выдачи,
SHA256/size из PostgreSQL, revoked authority, cross-account isolation и pagination/load.
Только operator disclosure metadata синтетические, opt-in `CORE_PRIVACY_BROWSER_FIXTURE=1`
требует test env, isolated DB/Redis и nonce; небезопасные окружения отвергаются. Production
identity/address/purpose definitions должны быть настроены отдельно и остаются fail-closed.
Контракт: [core-privacy.yaml](../../contracts/openapi/core-privacy.yaml).

## Операторские privacy requests и presence

`AdminPrivacyView` использует typed `PrivacyOperatorRequests` вместо generic request table.
На вкладках legal holds/advanced теперь используются typed workbench actions. Серверные фильтры
status/type/overdue и страницы по 25 не фильтруют только загруженную часть данных.
Поздние ответы отбрасываются, ошибка следующей страницы сохраняет предыдущую. Read permission
даёт просмотр, manage — формы действий. Для справочника людей/контактов отдельно требуется
`core.persons.read`; запроса всех людей без пагинации нет.

В [privacyOperator.ts](../../apps/frontend/src/shared/api/core/privacyOperator.ts) сохранены
фактические camelCase request DTO и snake_case block DTO. Новый `requests/options` отдаёт
только зарегистрированные purpose codes. Формы связывают person correction с subject ID
запроса; contact correction/resource restriction — с контактами этого человека. Несколько
исправляемых полей отправляются вместе, null/boolean не превращаются в пустую строку.
Для полученных вне кабинета запросов нужны реальная проверка личности и существующие evidence
references. Browser ISO dates нормализуются до секунд, иначе Core возвращает 422.

Ручные terminal transitions не предлагаются. Confirmation запускает correction deadline;
исправление, выдача или termination завершаются через серверные execution services. Продление
разрешено один раз и рассчитывается сервером. Снятие блока относится только к выбранной
записи. Termination может вернуть частичный исход либо отказ из-за неполных registries;
интерфейс не заменяет отказ успехом. Данные при этой операции не удаляются.

Request DTO содержит nullable `intakeChannel`: операторская выдача доступна только для offline
intake. При отсутствии поля во время rolling update кнопка не показывается. Исправлена ошибка
API actions: Yii Route::defaults преобразует скаляры в строки, поэтому `operator === true`
не работал. Теперь используется серверный строковый режим `intake`, права и subject/intake
проверки сервисов сохранены. Документы preview/download используют тот же raw transport,
one-shot/no-retry и session lifetime, что self-service.

[presenceStore.ts](../../apps/frontend/src/core/admin/presenceStore.ts) отправляет POST раз в
30 секунд только при сохранённом show_online_status=true и активной Core human session.
Hidden/offline/unmount/opt-out/session change отменяют запрос и таймер; поздний результат
не меняет новую сессию. Таймаут 10 секунд, backoff 60/120 секунд, параллельных heartbeat нет.
Online/unknown различаются, серверный TTL по умолчанию 90 секунд. Общий эфемерный
`X-Client-Instance-ID` передаётся в heartbeat и logout, чтобы выход мог удалить только
соответствующую запись присутствия. ID существует только в памяти страницы. Права видимости
по-прежнему проверяет серверный PresenceVisibilityPolicy вместе с отношениями модулей.

В TLS dev/preview Vite согласует HTTP/1.1 через ALPNCallback: это устраняет воспроизведённые
зависания HTTP/2 между браузером и локальным API proxy. Настройки проверки сертификатов
не ослабляются; исключение для self-signed остаётся внутри E2E request/browser contexts.
В normal browser E2E нет Chromium-флага отключения HTTP/2. Основа настройки —
[Node TLS ALPNCallback](https://nodejs.org/api/tls.html#tlscreateserveroptions-secureconnectionlistener).

## Core configuration: модули, значения и публикация

`/admin/modules` использует отдельный module list/detail и подтверждённые enable/disable.
`/admin/settings` содержит system settings, feature flags и просмотр актуальной публичной
конфигурации. Формы читают отдельный key/code перед edit/delete. Основной adapter —
`src/shared/api/core/configuration.ts`; серверный контракт —
[core-configuration.yaml](../../contracts/openapi/core-configuration.yaml), 17 операций.

`ConfigValueEditor` вводит scalar/object/list через рекурсивные поля без JSON textarea,
сохраняет false/0/null, проверяет числа, имена/дубликаты свойств и лимит 16 KB. Module settings
не имеют isPublic. Публикация системного значения требует отдельного acknowledgement;
public preview читает реальный anonymous API без cookies/Bearer, не применяет произвольные
ключи к приложению. Secret-like keys остаются запрещены сервером и adapter.

Feature flags используют справочник модулей с поиском, switch, range 0..100 и object editor.
При отсутствии module read permission справочник не вызывается, текущая binding сохраняется.
UI показывает конфигурацию флага; effective feature availability вычисляет backend.
Бизнес-схемы отдельных module values API пока не раскрывает: версия показывается как metadata.

`useConfigCollection` изолирует records/forms по lifetime и Core session epoch, отсекает
поздние результаты. 403 очищает данные, 503 сохраняет строки и блокирует изменения до refresh.
Неопределённый write не повторяется автоматически и требует явной сверки списка; закрытие
dialog этого требования не снимает. Read/manage permissions проверяются раздельно.

`verify-core-configuration.mjs` — HTTP fixtures, responsive/axe, RU/EN, readonly и unknown write.
`e2e-core-configuration.mjs` — настоящий disposable Core/PG/Redis, operator MFA, 17 API
operations через UI/API, PostgreSQL assertions и короткий read burst. Оба запускаются в CI.

## Core directory workflows и Commercial operator

`modules/admin/workbench/catalog.ts` описывает 53 прежних общих Core операции как конкретные
поля/запросы: organizations/persons/accounts, contacts, service credentials, RBAC/audit и privacy
workflows. `TypedFields`/`EntityPicker` используют доступные справочники и явную пагинацию;
поиск работает по загруженным записям, кнопка загружает следующие. При отсутствии read permission
справочник не запрашивается: для write-only оператора остаётся точная ссылка/код. Неизвестные
resource IDs и внешние документы не подменяются выдуманными вариантами. Четыре privacy
work-items API возвращают только id/name/status; distribution ограничен собственным субъектом
либо grants текущего account. Основные переходы учитывают состояние записи; backend проверяет
права и бизнес-правила повторно. Неопределённый write автоматически не повторяется.

`/operator` использует отдельный memory-only session на существующем Commercial issuer.
Клиентские/Core токены, cookies и storage не заимствуются. `/operator/me` проверяет реальные
privacy/financial operator authorization records; membership владельца клиента их не заменяет.
Read models и mutations проверяют полномочия при каждом вызове. Финансовые изменения требуют
MFA/recent auth и второго оператора; evidence review сохраняет неизменяемые references/hash.
При step-up форма остаётся открытой, подтверждение личности выполняется внутри неё; повторная
отправка явная. Новые операторские права рабочим аккаунтам автоматически не выдаются.
Прямое добавление Commercial membership вынесено в отдельную подтверждаемую форму существующего
активного пользователя; для приглашения нового человека остаётся invitation flow.

`npm run verify:workflows` проверяет UI с HTTP fixtures и все 63 прежних разрыва; это не paid
provider E2E. `npm run test:workbench-contracts` сверяет все методы/пути/permissions с Core PHP
routes. Реальные server suites, readiness и screenshots перечислены в [AUDIT](../AUDIT.md).

Периодический Billing проверяет отдельный browser acceptance с настоящим scheduler,
HTTP/PG/SMTP/MFA и синтетическим внешним provider wire. Только дополнительный
`compose.billing-clock.yaml` подключает clock images: readonly файл смещения одинаков
для PHP и PostgreSQL, CLI запускается тем же guarded entrypoint. Clock images отказывают
при production env, другой БД, отсутствии isolation/nonce. Production Compose/Dockerfiles
не подключают clock и не предоставляют HTTP API управления временем. Перед каждым скачком
тест приостанавливает оба своих контейнера, обновляет файл и возобновляет их; host clock
и monotonic timers остаются реальными. Реальное время прохождения месяца этим не доказано:
libfaketime перехватывает wall clock, а не все внутренние таймеры PostgreSQL/Go.
Описание механизма — [libfaketime](https://github.com/wolfcw/libfaketime).
