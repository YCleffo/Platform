# Обновление checkbox / switch / range — 2026-10-31

Настоящие checkbox inputs, переключатель с движущейся ручкой, ползунок с заполнением
и увеличением при удержании. Общие переменные цвета/времени, Light/Dark, reduced motion,
минимальная зона касания 44 px. Внешний вид проверен по снимкам production preview.

- [Чекбоксы, mobile RU](controls-mobile-ru.png), [switch, mobile RU](controls-mobile-switch.png).
- [Desktop Light](controls-desktop-light.png), [Desktop Dark](controls-desktop-dark.png).
- [Настройки Light](controls-settings-light.png), [настройки Dark](controls-settings-dark.png).
- [Бегунок при удержании](controls-slider-pressed.png), [mobile range](controls-mobile-slider.png), [320 px / 140% text](controls-320-large-text.png).
- [24 проверки управления](controls-results.json), [18 состояний axe](accessibility.json).

Frontend: 146 tests / 34 files, typecheck/build/lint/tokens/icons/styles — PASS.
Добавлены 5 unit/component tests. Browser: 24 assertions реальными mouse/touch/keyboard
событиями; disabled/mixed, отпускание вне range, Undo одним шагом, reload и reduced motion.
Existing appearance/menu/UX/responsive checks также пройдены на production preview.
Axe: 18 состояний / 0 автоматических A/AA violations; incomplete сохранены в JSON.

Проверка touch выполнена в Chromium с эмуляцией мобильного устройства; физическое
устройство iOS/Safari в этом проходе не использовалось. Backend/Docker не менялись
из-за этой UI-правки, рабочие БД не затронуты. Коммитов нет.
