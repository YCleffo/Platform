# Целевая структура проекта

Текущая структура включает Yii3 Core и отдельный Symfony Commercial Control Plane. Пустые разделы на будущее обозначены файлами README/.gitkeep, чтобы было понятно, где они будут располагаться.

```text
C:\\Platform
├── apps
│   ├── backend            Yii3 API + обработчик; на этапе 1 содержит SQL-миграции Core
│   ├── frontend           Vue 3 + TypeScript
│   ├── agent              агент Windows на C#/.NET
│   └── control-plane      Symfony Commercial Control Plane, central auth/onboarding, отдельная БД
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
│       ├── core            24-table Core DBML
│       ├── runtime         installation and entitlement DBML
│       └── commercial      Commercial Control Plane v2 DBML
├── scripts
├── compose.yaml
├── .env.example
├── .gitignore
└── README.md
```

Каталоги модулей backend/frontend будут отражать те же концептуальные модули, но не требуют создавать каждый будущий модуль с самого начала.

В `apps/control-plane/src` Phase 2 разделён на `Identity`, `Account`, `Catalog`
и инфраструктурный HTTP/Console слой. Control User, Commercial Account и их
авторизация не являются Yii/Diary сущностями.
