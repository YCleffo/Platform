# Защита транспорта, браузерная граница и секреты

## Граница браузера и TLS

Platform не шифрует JSON дополнительным самодельным протоколом. Правильная граница выглядит так:

```text
Открытые данные в браузере
    ↓
Шифрование TLS
    ↓
Транспорт HTTPS
    ↓
Завершение TLS
    ↓
Backend
```

HTTPS защищает содержимое запросов от прослушивания Wi-Fi, посредника без доверенного сертификата,
провайдера, промежуточной сети и стороннего наблюдателя. Он не скрывает данные от владельца браузера
или DevTools. Поэтому запрещены шифрование request/response body через JavaScript, обфускация Base64
и собственные «защищённые» протоколы поверх HTTPS.

## Production-транспорт

Промышленный маршрут трафика строго ограничен следующей схемой:

```text
Internet
    ↓
HTTPS :443
    ↓
TLS reverse proxy / Caddy
    ↓
общая с proxy, не опубликованная на host сеть caddy_public
    ↓
backend :80
```

Production compose не публикует порт backend на host. Контейнер доступен только reverse proxy через
внешнюю Docker-сеть `caddy_public`; внутренний HTTP на порту 80 не является публичной точкой входа.
Внешний Caddy обязан перенаправлять HTTP на HTTPS (оставляя порт 80 только для redirect/ACME),
получать и автоматически обновлять валидный сертификат и разрешать только TLS 1.2/1.3.

На production-host Caddy добавляет:

```http
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

HSTS не применяется к localhost. Локальная разработка продолжает работать по `http://localhost:8080`.

## Заголовки API и кеширование

Единый middleware добавляет `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
`X-Frame-Options: DENY` и ограничивающий CSP. Для `/api/auth/*` и `/api/core/*` он также добавляет
`Cache-Control: no-store` и `Pragma: no-cache`. Action не должны дублировать эту политику.

## Ошибки и журналирование

Необработанная ошибка возвращается как локализованная `http.internal_error` без сообщения исключения,
SQL, stack trace, путей или значений окружения. Логгер получает только стабильный код ошибки и тип
исключения, но не объект `Throwable`, его сообщение, аргументы или request body.

Request body по умолчанию не журналируется. Запрещено журналировать `Authorization`, `Cookie`,
`Set-Cookie`, пароли, исходные access/refresh/activation/reset-токены, SMTP/DB credentials,
security stamp и секрет fingerprint. Будущий HTTP access logger должен использовать deny-list/redaction
и ограничиваться request ID, методом, именем маршрута, статусом, длительностью, допустимым account ID,
IP, User-Agent и стандартизированным кодом ошибки.

## Generated artifacts

`tests/_output` — локальные временные чувствительные данные. Codeception может сохранять там response
body при падении теста, включая одноразовые токены, но каталог исключён из Git, Docker image и project ZIP.
Исходники тестов, конфигурация Codeception и fixtures остаются частью проекта.

## Контракт будущего browser-клиента

Bearer credentials запрещено хранить в `localStorage`, `sessionStorage`, IndexedDB, постоянных
Redux/Vue/Pinia stores, кеше service worker, URL, query или hash. Access token при Bearer-архитектуре
может находиться только в оперативной памяти процесса страницы.

Для native/mobile клиентов подходит существующий opaque Bearer/refresh API. До реализации frontend
для browser-клиента требуется отдельное решение: BFF с `HttpOnly Secure` cookie либо короткоживущий
access token в памяти и `HttpOnly/Secure` refresh-механизм. При добавлении cookie-аутентификации защита
от CSRF проектируется и вводится одновременно.

Предпочтительная production-топология — same-origin frontend и API (`https://platform.example.com/`
и `/api/*`). API не выставляет permissive CORS, не возвращает `Access-Control-Allow-Origin: *` и не
отражает произвольный Origin. Разные origins в будущем требуют явного allowlist; credentials вместе
с wildcard origin запрещены.

`ClientContext` остаётся внутренним объектом и не сериализуется целиком. Владелец аккаунта видит для
своих сессий только UX-данные: session ID, признак текущей сессии, browser/OS/device, IP, приблизительное
местоположение и временные метки. Хеш fingerprint, внутренние risk signals, состояние trusted proxy,
исходные forwarded headers и данные другого аккаунта наружу не выдаются.
