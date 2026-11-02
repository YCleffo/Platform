# Core admin console

The admin console lives in the customer SPA under `/admin` but uses a **separate Core
session and permission model**. Customer credentials do not work there; customer tokens are
not reused. Tokens stay in memory only, so a full page reload requires signing in again.

## Local sign-in

Create the first Core super administrator once:

```bash
docker exec -e CORE_BOOTSTRAP_PASSWORD='<strong-local-password>' \
  platform-backend-1 php yii core:bootstrap-superadmin \
  --email=admin@platform.local --given-name=Platform --family-name=Admin \
  --locale=ru-RU --timezone=UTC
```

Open [http://127.0.0.1:4176/admin/login](http://127.0.0.1:4176/admin/login) while the Vite
dev server is running. On the local development stack used for this implementation the
credentials are `admin@platform.local` and the password supplied to the bootstrap command.

## Pages

- Dashboard: live counts from Core admin APIs and account status distribution.
- Organizations: list/create, members list/add/remove.
- Persons: list/create, update/archive, contacts.
- Accounts: list/create, lifecycle, invitation, service credentials.
- RBAC: roles, permissions, role permission replacement, account role assignment.
- Modules: lifecycle, module settings.
- Settings: system settings and feature flags.
- Privacy operations: data-subject requests, legal holds, erasure, incidents, representations,
  distribution consents.
- Audit: Core audit trail, superadmin only.
- Finance compensations: intentionally blocked. Those endpoints use the Commercial control
  plane and need a separate Commercial operator permission model; exposing them through a
  Core session would be unsafe.

Create/edit/action bodies are JSON. This keeps advanced nested payloads available without
inventing fields the backend does not accept; invalid JSON is rejected before the request.
All lists and actions call real `/api/core/*` endpoints. The UI does not synthesize records.

## Local performance

The admin console calls Core through the `backend-fast` Compose service on
`127.0.0.1:8082`. It runs the production image target (baked code, no dev Xdebug) against the same
PostgreSQL and Redis. The regular `backend` dev service remains for hot reload, workers and
developer debugging.

Vite reads `apps/frontend/.env.local` (ignored by git) and proxies `/api` to 8082. If you change
Core backend code, rebuild/restart the fast service:

```bash
docker compose up -d --build --no-deps backend-fast
```

Measured locally: Core admin endpoints ~45-60 ms instead of ~1-3 s in the dev runtime.
The admin client caches the first page of every resource, renders cached rows immediately and
revalidates in the background; dashboard counters fill progressively. Person names are joined
from the cached persons list for the accounts table and shown in the top bar.

## Table and navigation behaviour

- Every paginated resource has a page-size dropdown (10/25/50/100) in the table footer using
  the shared `AppDropdown`; changing it resets the offset and reloads through the cache.
  The page-size control uses the compact dropdown size (32 px high, 96 px wide). Its menu
  uses the compact item density too (30 px rows, 12 px text, 4/8 px padding). Panels match
  the trigger width (at least 96 px), open under the trigger and clamp to the viewport edge.
- Table headers are sticky flush below the opaque admin top bar (top offset = 68 px). AppCard/table
  wrappers no longer create a vertical scroll container on desktop, so the page scrolls and the
  header stays pinned without rows showing through the gap.
- The expanded sidebar can be resized by dragging its right edge (200-340 px, persisted).
  Dragging below 200 px releases into the 68 px collapsed rail; dragging from the collapsed rail
  expands it again. Releasing outside the window/on pointer cancel reverts to the width before the
  drag. Keyboard users can focus the separator and use Arrow Left/Right.
- Collapsing the sidebar animates the current width to 68 px (the top bar height). Labels are clipped with
  `nowrap` instead of reflowing; the logo keeps the same x position and the collapse control is
  absolutely positioned beside it. In collapsed mode nav/section gaps collapse to 4/0 px so icon
  groups stay dense.
- Expired/revoked admin sessions redirect to `/admin/login?redirect=…` immediately instead of
  leaving a stale page with a session error banner.
- The table card has no inner padding: the sticky header and footer sit flush to the card edge;
  the footer uses symmetric 8 px padding and row actions live in a compact `Actions` dropdown.
- Tables use a fixed desktop layout so expanding the sidebar does not create page-level horizontal
  scroll; the table wrapper only scrolls horizontally below 1200 px. Row actions are grouped into
  a compact `Actions` dropdown, keeping rows short.
- The brand mark is vertically centered in the 68 px topbar row. Nav labels use the remaining width
  with ellipsis and expand/shrink with the sidebar; collapsed icons stay 18 px and centered.
- Table hover highlights the full hovered row and the full column under the pointer with pale
  accent tokens (`6%/4%`, hovered cell `9%`); the column header is highlighted over the card.
- Table cells use the same 8/12 px padding as buttons; headers stay sticky below the top bar. Labels, buttons and
  dropdowns opt out of text selection (`user-select: none`), while input/textarea/pre/code remain
  selectable.
- When collapsed, every navigation item uses the shared `AppTooltip` component. The link keeps
  its accessible name through aria-label; the tooltip is visual-only.
- Auth layouts no longer expose the Settings shortcut. Admin authentication stays on the
  separate Core account/session; customer credentials are not reused.
