# Requirements Document

## Introduction

This document specifies the requirements for converting the existing Laravel 9 POS system into a Windows desktop application (Option B). The shipping artifact is a single signed NSIS installer bundling an Electron shell, a Vue 3 SPA, the Laravel codebase, a portable PHP runtime, and a portable MariaDB server. All user interaction happens in the Vue SPA, which talks to Laravel exclusively through a versioned `/api/v1/` JSON API authenticated via Laravel Sanctum (stateful, same-origin cookies + CSRF). The application is offline-first: every core module flow works without internet, and online-only features (SMS, email, Pusher, online payment gateways, license refresh) are gated by a runtime connectivity check.

The locked product decisions captured below are reflected in the requirements wording:

1. The bundled relational database is MariaDB. SQLite is out of scope.
2. The supported operating system is Windows only.
3. The application is offline-first, with optional online-only features that degrade gracefully.
4. All 19 enabled modules listed in `modules_statuses.json` are in scope for v1.
5. API authentication for the SPA is Laravel Sanctum only. Passport is not used by the SPA.
6. License validation is performed locally against an Ed25519-signed license file. The Ed25519 public key is embedded in compiled JavaScript and the OS-level Authenticode signature on the installer is the trust boundary.
7. The user-facing UI is a full Vue 3 SPA. The only retained Blade artifacts are receipt templates rendered server-side and printed by the Electron print pipeline.

## Glossary

- **POS_Desktop_App**: The complete Windows desktop application (Electron shell + Vue SPA + Laravel API + MariaDB + PHP runtime).
- **Electron_Main**: The Electron main process (`electron/main.js`) that owns native lifecycle, supervises child processes, and creates the application window.
- **Server_Manager**: The Node module (`electron/server-manager.js`) that supervises the bundled PHP `artisan serve` process on `127.0.0.1:8000`.
- **DB_Manager**: The Node module (`electron/db-manager.js`) that supervises the bundled MariaDB process on `127.0.0.1:3307`.
- **Printer_Manager**: The Node module (`electron/printer-manager.js`) that handles receipt printing via the system printer or ESC/POS.
- **License_Validator**: The Node module (`electron/license-validator.js`) that performs offline Ed25519 verification of `license.dat`.
- **Auto_Updater**: The `electron-updater`-backed component that checks, downloads, and verifies application updates.
- **Vue_SPA**: The Vue 3 single-page application served from `frontend/dist/` and loaded into the BrowserWindow.
- **Connectivity_Store**: The Pinia store in the Vue SPA that tracks online/offline state via heartbeat and browser events.
- **Online_Guard**: The Laravel middleware that returns HTTP 503 with `code = "offline_required"` for online-only routes when offline detection fails.
- **Laravel_API**: All routes under `/api/v1/` served by the bundled PHP server.
- **Sanctum_Guard**: Laravel Sanctum stateful authentication (`auth:sanctum`) protecting `/api/v1/` routes.
- **Module_Route_Provider**: A Laravel service provider (`App\Providers\ModuleApiRouteProvider`) that auto-discovers and mounts module API route files.
- **Single_Instance_Lock**: The Electron mechanism that ensures only one POS_Desktop_App process runs per user session.
- **Writable_Data_Dir**: `%APPDATA%/POSSystem/`, the per-user directory holding `mariadb-data/`, Laravel `storage/`, `license.dat`, and logs.

## Requirements

### Requirement 1: Distribution and platform

**User Story:** As a shop owner, I want to install the POS as a single signed Windows installer, so that I can deploy the application without manually configuring PHP, MariaDB, or networking.

#### Acceptance Criteria

1. THE POS_Desktop_App SHALL be distributed as a signed NSIS installer that bundles the Electron shell, the Vue SPA build output, the Laravel codebase, a portable PHP 8.2 runtime, and MariaDB 10.11 binaries.
2. THE POS_Desktop_App SHALL declare Windows 10 or later (x64) as the only supported operating system.
3. WHEN the POS_Desktop_App is installed, THE Electron_Main SHALL resolve the Writable_Data_Dir at `%APPDATA%/POSSystem/` and create it with the installing user's permissions if it does not exist.
4. WHILE the POS_Desktop_App is running, THE Server_Manager and THE DB_Manager SHALL bind their listening sockets to the loopback address `127.0.0.1` only.

### Requirement 2: Application launch lifecycle

**User Story:** As a shop owner, I want the application to start its database, then its API server, then validate my license before showing the main window, so that the SPA never opens without its dependencies ready.

#### Acceptance Criteria

1. WHEN the POS_Desktop_App is launched, THE Electron_Main SHALL start the DB_Manager before starting the Server_Manager.
2. WHEN the DB_Manager reports ready, THE Electron_Main SHALL start the Server_Manager and SHALL wait for the Server_Manager to report ready before proceeding.
3. THE Server_Manager SHALL declare ready only after `GET http://127.0.0.1:8000/api/v1/health` returns HTTP 200 within 30 seconds of process spawn.
4. WHEN the Server_Manager has reported ready, THE Electron_Main SHALL invoke the License_Validator before creating the main BrowserWindow.
5. WHEN the License_Validator returns `valid = true`, THE Electron_Main SHALL create the main BrowserWindow loading the Vue_SPA from the local Laravel server origin.
6. WHILE the Electron_Main is running and the user has not requested quit, THE Server_Manager process and THE DB_Manager process SHALL both be running, OR THE Electron_Main SHALL display a non-dismissible startup-error screen.
7. IF the Server_Manager fails to reach ready within 30 seconds or its child process exits with a non-zero status three times within 60 seconds, THEN THE Electron_Main SHALL display a fatal startup-error screen showing the tail of `logs/laravel-server.log` and SHALL NOT create the main BrowserWindow.

### Requirement 3: Graceful shutdown

**User Story:** As a shop owner, I want closing the application to cleanly stop the database and the API server, so that my data is flushed to disk and no orphan processes remain.

#### Acceptance Criteria

1. WHEN the user requests application quit through the OS or the in-app menu, THE Electron_Main SHALL invoke `Server_Manager.stop()` before invoking `DB_Manager.stop()`.
2. WHEN `Server_Manager.stop()` is invoked, THE Server_Manager SHALL send SIGTERM to the PHP process and SHALL escalate to SIGKILL if the process is still alive after 5 seconds.
3. WHEN `DB_Manager.stop()` is invoked, THE DB_Manager SHALL initiate `mysqladmin shutdown` against the bundled MariaDB and SHALL fall back to SIGTERM if `mysqladmin shutdown` does not return within 10 seconds.
4. WHEN the `before-quit` handler in Electron_Main resolves, THE PHP server process and THE MariaDB process SHALL have exited as verified by PID check before THE Electron_Main process itself exits.
5. WHEN the POS_Desktop_App exits cleanly, THE DB_Manager SHALL have flushed InnoDB buffers such that the next launch does not require InnoDB crash recovery.

### Requirement 4: Single-instance lock

**User Story:** As a shop owner, I want a second double-click on the launcher to focus the running window instead of starting a duplicate, so that I never end up with two PHP servers fighting for port 8000.

#### Acceptance Criteria

1. WHEN the POS_Desktop_App is launched while another POS_Desktop_App process owned by the same user is already running, THE Single_Instance_Lock SHALL prevent the new process from spawning a second PHP server and a second MariaDB server.
2. WHEN a duplicate launch is detected, THE Electron_Main of the original process SHALL bring its existing main BrowserWindow to the foreground and SHALL focus it.
3. THE Single_Instance_Lock SHALL release automatically when the owning Electron_Main process exits, so that subsequent launches succeed normally.

### Requirement 5: First-run database initialization idempotence

**User Story:** As a shop owner, I want the database to be initialized only once and never re-initialized on subsequent launches, so that my data is preserved across restarts and upgrades.

#### Acceptance Criteria

1. WHEN the POS_Desktop_App launches and `%APPDATA%/POSSystem/mariadb-data/` does not exist, THE DB_Manager SHALL run `mariadb-install-db`, set a randomly generated root password, create the `pos_app` user, create the `pos_db` database, write the resulting credentials to the Laravel `.env`, and run `php artisan migrate --force`.
2. WHEN the POS_Desktop_App launches and `%APPDATA%/POSSystem/mariadb-data/` exists with an initialized data dictionary, THE DB_Manager SHALL NOT run `mariadb-install-db` and SHALL NOT alter the existing `pos_app` user's password.
3. WHEN the POS_Desktop_App launches against an already-initialized data directory, THE DB_Manager SHALL run `php artisan migrate --force` so that pending migrations from upgrades are applied.
4. THE DB_Manager SHALL store the generated MariaDB root password using Windows DPAPI via Electron's `safeStorage` API.

### Requirement 6: License file installation and validation

**User Story:** As a shop owner, I want to install my license by selecting the file my distributor sent me, so that I can activate the application without an internet connection.

#### Acceptance Criteria

1. WHEN the License_Validator is invoked at startup, THE License_Validator SHALL read `%APPDATA%/POSSystem/license.dat`, parse it as a `LicenseFile` JSON document, and verify its `signature` against the embedded Ed25519 public key selected by `publicKeyId` over the canonical JSON of `payload`.
2. WHEN the License_Validator finds no `license.dat` at `%APPDATA%/POSSystem/license.dat`, THE License_Validator SHALL return `valid = false` with `reason = NOT_FOUND`.
3. IF the `license.dat` bytes cannot be parsed as a valid `LicenseFile`, THEN THE License_Validator SHALL return `valid = false` with `reason = BAD_FORMAT`.
4. IF the `LicenseFile.publicKeyId` does not match any embedded public key, THEN THE License_Validator SHALL return `valid = false` with `reason = TAMPERED_PUBLIC_KEY`.
5. IF the Ed25519 verification of `signature` over canonical JSON of `payload` fails, THEN THE License_Validator SHALL return `valid = false` with `reason = BAD_SIGNATURE`.
6. IF `payload.productId` is not equal to `"pos-desktop-v1"`, THEN THE License_Validator SHALL return `valid = false` with `reason = WRONG_PRODUCT`.
7. IF `payload.expiresAt` is not null and is earlier than the current system clock, THEN THE License_Validator SHALL return `valid = false` with `reason = EXPIRED`.
8. IF `payload.machineFingerprint` is not null and does not equal the current machine fingerprint, THEN THE License_Validator SHALL return `valid = false` with `reason = MACHINE_MISMATCH`.
9. WHEN the License_Validator returns any non-OK reason, THE Vue_SPA SHALL route to the `/license` view, display the failure reason, and offer a "Browse for license file" action wired to `electronAPI.installLicenseFromFile`.
10. WHEN `installLicenseFromFile` is invoked with a path whose contents pass all validation steps in this requirement, THE License_Validator SHALL atomically write those bytes to `%APPDATA%/POSSystem/license.dat` and SHALL return `valid = true`.
11. WHEN `installLicenseFromFile` is invoked with a path whose contents fail validation, THE License_Validator SHALL NOT modify `%APPDATA%/POSSystem/license.dat` and SHALL return the failure reason.

### Requirement 7: License tamper resistance

**User Story:** As a distributor, I want any modification to the on-disk license file to be detected on the next validation, so that customers cannot extend their license by editing the file.

#### Acceptance Criteria

1. WHEN any byte of `%APPDATA%/POSSystem/license.dat` is modified after a successful install in a way that does not produce a valid Ed25519 signature under an embedded public key, THE License_Validator SHALL return `valid = false` on the next call to `validate()`.
2. THE License_Validator SHALL pin each embedded Ed25519 public key by SHA-256 hash and SHALL return `valid = false` with `reason = TAMPERED_PUBLIC_KEY` if a recomputed hash at startup does not match the pinned hash.
3. THE Laravel `pos_boot()` function SHALL be replaced with a no-op stub that always returns valid, so that no Laravel code path performs an external license HTTP call.

### Requirement 8: API JSON purity

**User Story:** As a frontend developer, I want every response from `/api/v1/*` to be JSON, so that the SPA never has to parse Blade HTML or follow a session-based redirect.

#### Acceptance Criteria

1. THE Laravel_API SHALL serve all routes under the path prefix `/api/v1/`.
2. WHEN the Laravel_API receives a request to any path matching `/api/v1/*`, THE Laravel_API SHALL respond with `Content-Type: application/json` or `Content-Type: application/problem+json` and a body that is parseable as JSON.
3. THE Laravel_API SHALL NOT render Blade views and SHALL NOT issue HTTP redirects to Blade-rendered URLs in response to any request whose path matches `/api/v1/*`.
4. THE Laravel_API controllers under `app/Http/Controllers/Api/` SHALL NOT use the `session()` helper and SHALL NOT depend on PHP session state for request handling.
5. WHERE business or user context is required by an `/api/v1/*` controller, THE Laravel_API SHALL load that context through the `SetSessionDataApi` middleware into a request-scoped container instead of PHP sessions.

### Requirement 9: Module API auto-mounting for all 19 modules

**User Story:** As a module author, I want my module to expose its REST API simply by adding a `Routes/api.php` file, so that I do not have to edit any framework or core file to register the routes.

#### Acceptance Criteria

1. WHEN Laravel boots, THE Module_Route_Provider SHALL read `modules_statuses.json` and SHALL enumerate every module name whose flag is `true`.
2. WHEN the Module_Route_Provider encounters a module `m` whose flag is `true` AND a file `Modules/{m}/Routes/api.php` exists on disk, THE Module_Route_Provider SHALL mount every route declared in that file under the path prefix `/api/v1/modules/{kebab(m)}/` with the middleware stack `["api", "auth:sanctum", "SetSessionDataApi"]`.
3. WHERE a module flagged `true` has no `Routes/api.php` file, THE Module_Route_Provider SHALL skip that module without raising an error.
4. THE Module_Route_Provider SHALL register itself in `config/app.php` and SHALL NOT require edits to any file under `Modules/{m}/` to enable mounting for module `m`.
5. THE POS_Desktop_App SHALL include all 19 modules listed in `modules_statuses.json` (Essentials, Accounting, AssetManagement, Cms, Connector, Crm, Ecommerce, FieldForce, Manufacturing, ProductCatalogue, Project, Repair, Spreadsheet, Superadmin, Woocommerce, AiAssistance, Hms, InboxReport, CustomDashboard) with their flags set to `true` in v1.

### Requirement 10: Sanctum login, logout, and session round-trip

**User Story:** As a cashier, I want to log in to the SPA with my email and password and have the system remember me through CSRF-protected cookies, so that I can use the app without re-entering credentials on every request.

#### Acceptance Criteria

1. WHEN the Vue_SPA invokes the Sanctum CSRF endpoint at `GET /sanctum/csrf-cookie`, THE Laravel_API SHALL respond with HTTP 204 and SHALL set the `XSRF-TOKEN` cookie.
2. WHEN the Vue_SPA submits `POST /api/v1/auth/login` with a valid email, password, and `device_name` after obtaining the CSRF cookie, THE Sanctum_Guard SHALL authenticate the user, THE Laravel_API SHALL set the session cookie, and THE Laravel_API SHALL return HTTP 200 with `LoginResponse` containing the authenticated user, business, permissions, locations, currency, and enabled modules.
3. WHEN an authenticated browser context that received a successful login response calls `GET /api/v1/auth/me`, THE Laravel_API SHALL return HTTP 200 with the same user identity that was authenticated by login.
4. WHEN an authenticated browser context calls `POST /api/v1/auth/logout`, THE Sanctum_Guard SHALL invalidate the session, and any subsequent call to `GET /api/v1/auth/me` from that browser context SHALL return HTTP 401 with body `{"code":"unauthenticated"}`.
5. IF a state-changing request to `/api/v1/*` arrives without a valid CSRF token, THEN THE Sanctum_Guard SHALL reject the request with HTTP 419.
6. THE Sanctum_Guard SHALL be the only authentication guard used by the Vue_SPA, and THE Laravel_API SHALL NOT issue Passport tokens for SPA-initiated logins.

### Requirement 11: Connectivity heartbeat and indicator

**User Story:** As a cashier, I want the application to clearly show whether I am online or offline and to update that state without flickering when my connection briefly drops, so that I can trust the indicator before attempting an online-only action.

#### Acceptance Criteria

1. WHILE the Vue_SPA is running, THE Connectivity_Store SHALL run a heartbeat at a 30-second interval that sets `online = (navigator.onLine = true) AND (HEAD https://www.gstatic.com/generate_204 returns HTTP 204 within 3 seconds)`.
2. WHEN the browser fires an `online` or `offline` window event, THE Connectivity_Store SHALL update `online` and `lastCheckedAt` immediately without waiting for the next heartbeat tick.
3. THE Connectivity_Store SHALL debounce changes to `online` such that a flap shorter than 5 seconds in either direction does not propagate a state change to subscribers.
4. WHILE `Connectivity_Store.online = false`, THE App.vue layout SHALL render a visible global "Offline" indicator in the top bar.
5. WHILE `Connectivity_Store.online = false`, every action button bound to an online-only feature in the Vue_SPA SHALL be in the disabled state and SHALL display a tooltip explaining offline mode.
6. WHEN `Connectivity_Store.online` transitions from `false` to `true`, online-only action buttons SHALL automatically return to the enabled state without requiring a page reload.

### Requirement 12: Offline gating of online-only features

**User Story:** As a shop owner, I want the application to refuse to attempt SMS sends, online payment captures, and other internet-dependent actions when offline, so that my staff is never confused by silent failures or hung requests.

#### Acceptance Criteria

1. WHEN any Vue_SPA API service wrapping an online-only feature is invoked while `Connectivity_Store.online = false`, THE Vue_SPA SHALL NOT issue an HTTP request to an external host and SHALL return a typed `OfflineUnavailable` error to the caller.
2. WHEN the Laravel_API receives a request on a route protected by the Online_Guard middleware while online detection has failed, THE Online_Guard SHALL respond with HTTP 503 and body `{"code":"offline_required"}`.
3. THE Online_Guard SHALL be applied to every Laravel route that performs outbound calls for SMS sending, email sending, Pusher publish, license refresh, or online payment gateway capture.
4. WHILE `Connectivity_Store.online = false`, THE Vue_SPA SHALL continue to permit every offline-capable flow defined in the design (POS sale, purchases, products, contacts, reports against local data, accounting, inventory).
5. WHEN connectivity is restored, any in-progress retry of an online-only action SHALL be re-enabled by the Connectivity_Store without forcing the user to navigate or reload.

### Requirement 13: POS sale and receipt round-trip

**User Story:** As a cashier, I want completing a sale to immediately produce a printable receipt with every line, total, and payment correctly reflected, so that I can hand the customer an accurate receipt every time.

#### Acceptance Criteria

1. WHEN the Vue_SPA submits `POST /api/v1/pos/sales` with a valid sale payload, THE Laravel_API SHALL persist the sale and SHALL return HTTP 201 with the persisted sale's identifier.
2. WHEN the Vue_SPA calls `GET /api/v1/pos/sales/{id}/receipt` for a sale just persisted, THE Laravel_API SHALL render the retained Blade receipt template and SHALL return its HTML in a JSON envelope.
3. THE rendered receipt HTML SHALL contain every line item, every tax line, every payment line, and the grand total exactly as persisted by the create call.
4. WHEN the Vue_SPA invokes `electronAPI.printReceipt(html, target)` with the HTML returned by the receipt endpoint, THE Printer_Manager SHALL accept the HTML without error.
5. WHEN the Printer_Manager renders the receipt to PDF, the resulting PDF SHALL contain every line item, total, and payment line that appears in the persisted sale.
6. WHERE the Vue_SPA includes Blade-rendered HTML, the rendering SHALL be limited to receipt templates and SHALL NOT extend to any other user-facing screen.

### Requirement 14: Receipt printer fallback to PDF

**User Story:** As a cashier, I want to be able to save a receipt as a PDF when no printer is available or printing fails, so that I never lose the ability to give the customer a receipt.

#### Acceptance Criteria

1. IF the configured target printer cannot be resolved by the Printer_Manager, THEN THE Printer_Manager SHALL return `{ ok: false, error: "printer_not_found" }` over IPC.
2. IF `webContents.print()` rejects during printing, THEN THE Printer_Manager SHALL return `{ ok: false, error: "<reason>" }` over IPC and SHALL NOT crash the Electron_Main.
3. WHEN the Vue_SPA receives `{ ok: false, error: "printer_not_found" }` or any other print failure, THE Vue_SPA SHALL display a non-blocking toast and SHALL offer a "Print to PDF" action.
4. WHEN the user accepts the "Print to PDF" fallback, THE Printer_Manager SHALL render the same receipt HTML to a PDF via `webContents.printToPDF()` and SHALL save it to a user-selected path.
5. THE printer target setting SHALL be stored per business location.

### Requirement 15: Auto-update integrity and offline behavior

**User Story:** As a shop owner, I want the application to update itself silently when an update is available and refuse any update whose signature does not match the publisher, so that I always have the latest version without risk of installing a tampered binary.

#### Acceptance Criteria

1. WHEN the Electron_Main has finished startup and the License_Validator returned `valid = true`, THE Auto_Updater SHALL check the configured update feed for a newer version.
2. IF the Auto_Updater receives an update payload whose Authenticode signature does not match the embedded publisher certificate, THEN THE Auto_Updater SHALL reject the update, SHALL log the rejection, and SHALL NOT prompt the user.
3. IF the Auto_Updater cannot reach the configured update feed because `Connectivity_Store.online = false` or the request times out, THEN THE Auto_Updater SHALL silently skip the update for the current session, SHALL log the skip, and SHALL NOT display any UI interruption.
4. THE Auto_Updater SHALL retry the update check on the next launch and SHALL keep the application functional on the current installed version indefinitely while offline.
5. WHEN the Auto_Updater downloads a signed update successfully, THE Auto_Updater SHALL notify the Vue_SPA via the `onUpdateAvailable` IPC channel, and THE Vue_SPA SHALL surface a non-blocking "Restart to update" prompt.
