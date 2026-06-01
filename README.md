# POS Desktop App

Windows desktop conversion of the existing Laravel 9 POS system. The shipping
artifact is a single signed NSIS installer bundling an Electron shell, a Vue 3
SPA, the Laravel codebase, a portable PHP 8.2 runtime, and MariaDB 10.11.

The full design lives at
[`.kiro/specs/desktop-app-conversion/`](./.kiro/specs/desktop-app-conversion/)
(`design.md`, `requirements.md`, `tasks.md`).

## Repository layout

```
pos_system/         this Laravel application (existing)
├── app/            existing Laravel code
├── Modules/        existing nwidart/laravel-modules (19 modules in v1)
├── routes/         api.php gets a /api/v1 group; web.php trims to receipt + auth fallback
├── electron/       Electron main process (added in Phase 1+)
├── frontend/       Vue 3 + Vite SPA (added in Phase 1+)
└── bin/win/        bundled PHP + MariaDB Windows binaries (populated by build)
```

The npm workspaces in this root `package.json` cover `electron/` and
`frontend/`. The Laravel application itself is managed by Composer at the
repo root and is unchanged structurally — new code (API controllers,
resources, license service) is added without moving any existing controller.

## Locked product decisions

These are reflected throughout the spec; see `requirements.md` for the
full ruleset.

| # | Decision |
|---|---|
| 1 | Bundle MariaDB 10.11 (no SQLite migration) |
| 2 | Windows only (single NSIS target) |
| 3 | Offline-first with optional online-only features |
| 4 | All 19 modules listed in `modules_statuses.json` are in scope for v1 |
| 5 | Sanctum stateful only for the SPA (Passport stays installed but unused) |
| 6 | Local Ed25519-signed license file; OS Authenticode is the trust boundary |
| 7 | Full Vue 3 SPA; Blade retained only for receipt rendering |

## Prerequisites

- Node.js 18.17+ (see `engines` block in each `package.json`).
- PHP 8.0+ and Composer for the Laravel side. Day-to-day development can use
  the system PHP; the bundled `bin/win/php` is the runtime that ships in the
  installer.
- A working clone of the existing Laravel app under this directory (this
  repository), with `composer install` already run and a `.env` configured.

## Top-level scripts

Run from the repo root.

| Script | What it does |
|---|---|
| `npm install` | Installs the `electron/` and `frontend/` workspaces in one shot |
| `npm run dev` | Starts the Vite dev server for the Vue SPA |
| `npm run build:frontend` | Builds the Vue SPA into `frontend/dist/` |
| `npm run build:electron` | Lints the Electron shell (build/dist comes from task 11.x) |
| `npm run dist` | Frontend + Electron build pipeline |
| `npm run lint` | Lints every workspace that defines a `lint` script |
| `npm run test` | Runs every workspace test suite (vitest) |

The Laravel test suite is run separately with `vendor/bin/phpunit` (or
`vendor/bin/pest` once Pest is added in task 2.1).

## Working through the spec

The implementation is broken into 11 phases in
[`.kiro/specs/desktop-app-conversion/tasks.md`](./.kiro/specs/desktop-app-conversion/tasks.md).
Tasks are dispatched one at a time by the Kiro orchestrator and each task
references the requirements (`R<n>.<m>`) and correctness properties (`P<n>`)
it validates.

Phase 1 (this phase) wires up the monorepo. Phases 2–7 build the Laravel
JSON API. Phase 8 builds the Electron shell (server + DB managers, license
validator). Phase 9 builds the Vue SPA. Phase 10 wires up printing.
Phase 11 ships the signed Windows installer.
