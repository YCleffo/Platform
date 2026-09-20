# Target project tree

Phase 1 creates the stable root and the parts we need immediately. Empty/future
areas are represented by README/.gitkeep files so you can see where everything goes.

```text
C:\\Platform
├── apps
│   ├── backend            Yii3 API + worker; phase 1 currently has Core SQL migrations
│   ├── frontend           Vue 3 + TypeScript
│   └── agent              C#/.NET Windows agent
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
│   └── database/core
├── scripts
├── compose.yaml
├── .env.example
├── .gitignore
└── README.md
```

Backend/frontend module folders will mirror the same conceptual modules but do not
force every future module to exist on day one.
