# PLATFORM — PLATFORM_09_R2 REMAINING GAPS & DEFERRED ITEMS

**Date:** 2026-11-25
**Scope:** Non-blocking items intentionally scoped outside `PLATFORM_09_R2` (or addressed in `PLATFORM_10` / `PLATFORM_11`).

1. **Background Media Transcoding / Waveform Extraction Worker (`GATE 8` partial deferment):**
   - Synchronous binary upload, S3 byte/SHA-256/finfo MIME validation, and client WebAudio waveform extraction (`AudioPlayer.vue`) are live and verified.
   - Asynchronous FFmpeg server-side transcoding/thumbnail worker remains deferred to background pipeline work without blocking `PLATFORM_09_R2_LOCAL_READY`.

2. **Unsupported `matching` Question Type & Activity Engine Extraction:**
   - Removed as a non-functional stub during `PLATFORM_09`/`R2`; full implementation of `matching`, `ordering`, `long_text`, and generalized cross-module `Activity` extraction are scheduled directly in `PLATFORM_11`.

3. **Transport Polling in Messaging:**
   - `EducationMessenger.vue` currently relies on periodic REST polling; WebSocket / Redis Pub/Sub Realtime delivery and standalone `Chat` extraction are scheduled in `PLATFORM_10`.

4. **Environment Note (Local vs Remote CI / Production):**
   - All `PLATFORM_09_R2` verifications ran locally against Docker Compose (`platform-backend`, `platform-postgres`, `platform-redis`, `platform-seaweedfs-1`) and local Vite/Chromium. Remote GitHub Actions CI and native Windows desktop VM acceptance remain separate release gates.
