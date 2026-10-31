# Frontend Platform

SPA на Vue 3, TypeScript strict и Vite. Использует Commercial Control Plane по
[OpenAPI](../../contracts/openapi/control-plane.yaml). Токены авторизации живут только
в памяти; persistent storage используется для темы, языка и внешнего вида. После перезагрузки нужен вход.

## Границы приложения

| Слой | Ответственность |
| --- | --- |
| `app` | Router, layouts, theme, appearance preferences, сборка приложения |
| `core` | Один auth/session owner, account context, композиция HTTP-клиента |
| `modules/commercial` | auth, onboarding, catalog, account, security, invitations |
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
| `/cabinet` | Список и создание Commercial Accounts |
| `/accounts/:accountId` | Overview, сведения и текущие permissions |
| `/accounts/:accountId/members` | Список, изменение ролей, removal; защита последнего owner |
| `/accounts/:accountId/invitations` | Создание, список, resend, revoke |
| `/invitation` | Публичный preview; accept/decline после входа |
| `/settings/security` | MFA enrollment, recovery codes, sessions, step-up |
| `/settings` | Тема, язык, размеры, отступы, анимации и тени; доступно без входа |
| `/settings/components` | Рабочие примеры общих dropdown и контекстного меню |

Invitation token захватывается из `#token=…`, удаляется из адресной строки и хранится
в памяти. Preview не меняет данные. Account permissions вычисляет сервер и повторно
проверяет при мутациях; UI скрывает недоступные действия.

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
Одиночная модель — строка, множественная — массив строк. Родитель с children открывает
раздел, выбирается конечный пункт. Multiple оставляет меню открытым и переключает
checkbox-состояния; clearable сбрасывает выбор. Внешний `<label for>` связан с id кнопки,
а обязательный `label` даёт ей доступное имя даже без внешней подписи.
Компонент эмитит модель; сериализация формы и серверная валидация остаются у вызывающего кода.

```vue
<AppDropdown v-model="language" label="Язык" :options="languages" searchable />
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

`verify-ux.mjs` проверяет undo/presets/точные шаги и выполняет 30 циклов меню с 10 диалогами;
число глобальных overlay listeners должно вернуться к исходному. Замер полного цикла
Playwright включает автоматизацию и не является метрикой input latency.
`verify-accessibility.mjs` использует axe-core для public/auth/settings/cascade/modal/mobile
в обеих темах, без отключения правил A/AA. Это дополняет проверку клавиатуры и скриншотов,
но не доказывает полное соответствие WCAG: [границы автоматической проверки](https://playwright.dev/docs/accessibility-testing).

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
реальные account и invitation/MFA/membership flows. Guard требует isolation nonce,
подтверждённый сервером. Рабочие БД для этого не используются; после job ресурсы удаляются.

### Checkbox, switch и range

`AppCheckbox` и `AppSwitch` находятся в `shared/ui/fields`, принимают boolean `v-model`,
обязательные `id`/`label`, `description`, `disabled` и `name`. Checkbox дополнительно
поддерживает `indeterminate`, `required` и `value`. Это настоящие HTML inputs: сохраняются
Space/Tab, label click, disabled и участие в формах. `indeterminate` управляется отдельно
от значения. Подсказка и внешний `aria-describedby` объединяются; имя берётся из label.

Switch применяется для немедленного изменения настройки (например, анимаций), checkbox —
для выбора пунктов. Примеры с общим/частичным выбором и disabled доступны на
`/settings/components`; checkbox также есть в живом preview настроек.

`AppRange` сохраняет нативное управление мышью, touch и клавиатурой. Заполнение дорожки
обновляется без задержки, бегунок растёт при удержании; отпускание снаружи, отмена touch,
blur и отключение завершают редактирование. Drag объединяется в один шаг Undo.
Цвета используют `--theme-transition`, геометрия — `--motion-control-duration` и
`--motion-spring-ease`; скорость и reduced motion наследуются из настроек сайта.

`BASE_URL=http://127.0.0.1:5173 npm run verify:controls` проверяет 24 browser assertions:
mouse/touch/keyboard, mixed/disabled, release outside, Undo, reload, reduced motion и
320 px / 140% text. Скрипт включён в browser CI; screenshots пишутся в игнорируемую
папку `screenshots`. Проверенные снимки: [набор controls](../operations/evidence/controls-2026-10-31/README.md).

Результаты и production-ограничения: [критерии выпуска](../operations/PRODUCTION_READINESS_PROGRESS.md).
Дальнейшие модули: [единый roadmap](../roadmap/PLATFORM_ROADMAP.md).
Будущие графики: [контракт](CHARTS_CONTRACT.md).
