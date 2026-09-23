# Целевая структура проекта

На этапе 1 создаётся стабильная структура проекта и необходимые сейчас компоненты. Пустые разделы и разделы на будущее обозначены файлами README/.gitkeep, чтобы было понятно, где они будут располагаться.

```text
C:\\Platform
├── apps
│   ├── backend            Yii3 API + обработчик; на этапе 1 содержит SQL-миграции Core
│   ├── frontend           Vue 3 + TypeScript
│   └── agent              агент Windows на C#/.NET
├── infra
│   ├── postgres
│   ├── redis
│   ├── nginx
│   ├── centrifugo
│   ├── livekit
│   ├── certbot
│   └── observability
├── contracts
│   ├── openapi
│   └── events
├── docs
│   ├── architecture
│   └── database
│       ├── core            frozen 23-table Core DBML
│       └── runtime         installation and entitlement DBML
├── scripts
├── compose.yaml
├── .env.example
├── .gitignore
└── README.md
```

Каталоги модулей backend/frontend будут отражать те же концептуальные модули, но не требуют создавать каждый будущий модуль с самого начала.
