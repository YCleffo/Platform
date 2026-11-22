# FRONTEND_09_FINAL — визуальные результаты

Эталон публичных экранов сделан на `4cf8c6d4`. Все 12 PNG для Settings, Commercial Login и Core Login совпали с эталонами по SHA-256. Матрица содержит четыре сочетания RU/EN и Light/Dark. Изменение дорожки ползунка Settings подтверждено отдельным замером стилей внутри Chromium. [Публичный отчёт](../../apps/frontend/screenshots/frontend09/visual-report.json)

Ручной разбор четырёх замечаний Settings показал, что axe направлял к кнопкам `+` и `−`. У кнопок проверены локализованные имена Increase/Decrease и «Увеличить»/«Уменьшить». Видимые символы сверены с семантикой действия. Измерено отношение контраста дорожки 7,69:1 в Light и 6,32:1 в Dark, символов 17,75:1 в Light и 16,96:1 в Dark. [Axe и измерения](../../apps/frontend/screenshots/accessibility.json) · [WCAG 2.5.3](https://www.w3.org/WAI/WCAG22/Understanding/label-in-name.html) · [WCAG 1.4.11](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html)

Native Page Zoom в Google Chrome прошёл шесть проверок на `/settings`, `/login` и `/admin/login` при 200% и 400%. Покрыты Tab, Shift+Tab, а в Settings также Arrow и Escape с восстановлением фокуса. Эффективные размеры составили 632×464 и 316×232, горизонтального overflow не было. Авторизованные кабинеты и NVDA остаются отдельными ручными проверками. [Замеры масштаба](../../apps/frontend/screenshots/frontend09-final-settings-browser-zoom.json)

Артефакты авторизованных кабинетов содержат PNG и соседний JSON с viewport, локалью, темой, SHA-256 PNG, шириной документа, метриками Chromium и результатами axe. Скриншоты создаются на изолированном API с тестовыми учётными записями. Волатильные значения маскируются по селектору только в конкретном сценарии. Эти снимки служат начальными эталонами для последующего сравнения.

Четыре снимка Commercial Account и два снимка Operator прошли axe без автоматически обнаруженных нарушений. Для Account на 393 px отдельно измерен контраст основных вкладок. Минимум 5,82:1. Кнопка скрытых хлебных крошек с видимым многоточием сохраняет локализованное доступное имя и отмечена для ручной проверки.

Для Core Settings сделаны снимки при всех восьми заданных размерах экрана. Девятый снимок фиксирует открытый диалог с ошибкой валидации после смены языка и темы. Во всех девяти автоматических замерах отсутствуют нарушения axe и горизонтальный overflow. [Снимки и отчёты Core](../../apps/frontend/screenshots/frontend09-final/authenticated/core-admin)

Итоговый набор содержит 31 авторизованный снимок. Пройдены автоматические проверки ширины документа и axe у Core Settings, Commercial Account, Operator, Teacher и Parent. У Teacher заново сняты кнопки дней недели, у Parent — выбор языка. Метрика суммарных layout shifts у Teacher накапливалась в течение нескольких переходов и смен viewport. Такой замер оставлен в артефактах для дальнейшего сравнения с исходной ревизией. [Все авторизованные снимки](../../apps/frontend/screenshots/frontend09-final/authenticated)

[Матрица](FRONTEND_09_AUTHENTICATED_MATRIX.md) · [Отчёт](FRONTEND_09_FINAL_ACCEPTANCE.md)
