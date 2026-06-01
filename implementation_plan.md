# POS System — Desktop App Conversion: Option B Implementation Plan

> **Approach:** Electron shell + Laravel REST API backend + Vue 3 frontend  
> **Strategy:** Run the existing Laravel app as a local HTTP server inside Electron. Progressively replace Blade views with a Vue 3 SPA that consumes a new JSON API layer. Bundle PHP binary + MySQL/MariaDB for distribution.

---

## Overview

Option B converts the POS system into a true desktop application with a clean separation of concerns:

```
┌─────────────────────────────────────────────────────┐
│                 Electron Shell                       │
│  ┌──────────────────┐   ┌──────────────────────────┐ │
│  │  Vue 3 Frontend  │◄──│  BrowserWindow           │ │
│  │  (Vite + Pinia)  │   │  (Chromium renderer)     │ │
│  └────────┬─────────┘   └──────────────────────────┘ │
│           │ HTTP/REST API                             │
│  ┌────────▼─────────────────────────────────────────┐ │
│  │    Laravel 9 API Server (php artisan serve)      │ │
│  │    Port: 8000 (localhost only)                   │ │
│  └────────┬─────────────────────────────────────────┘ │
│           │                                           │
│  ┌────────▼──────────┐                               │
│  │  MySQL / MariaDB  │ (bundled, port 3307)          │
│  └───────────────────┘                               │
└─────────────────────────────────────────────────────┘
```

---

## User Review Required

> [!IMPORTANT]
> **Database engine choice** — The plan defaults to bundling **MariaDB** (drop-in MySQL replacement). If you prefer migrating to **SQLite** instead (lighter, no separate process), this requires rewriting ~40 raw MySQL queries (date functions, GROUP_CONCAT, etc.). Please confirm your preference before Phase 1 starts.

> [!IMPORTANT]
> **Scope of Vue migration** — This plan migrates **all screens** to Vue 3. If you want to preserve some Blade views in a WebView for complex screens (e.g., Reports, Restaurant), scope can be narrowed. Full Vue migration is recommended for long-term maintenance.

> [!WARNING]
> **License / Envato purchase code** — The existing app has a license check in `helpers.php::pos_boot()` that calls an external validation server. For desktop distribution, this will fail offline. The license mechanism needs to be adapted to a local license file model.

---

## Open Questions

1. **Target platforms** — macOS only, or Windows + Linux too? (Affects Electron packaging and PHP binary choices)
2. **Database** — Bundle MariaDB or migrate to SQLite?
3. **Offline-first vs online-connected** — Should the desktop app work 100% offline, or can it require internet for some features (payment gateways, SMS, etc.)?
4. **Module scope** — All 19 modules, or only core POS + Inventory + Accounting?
5. **API authentication** — Laravel Passport (OAuth2, already installed) or simpler token-based auth (Sanctum)?

---

## Proposed Changes

---

### Phase 1 — Project Setup & Repository Structure

**Goal:** Establish the monorepo layout, tooling, and development environment.

#### [MODIFY] Directory structure (new top-level `electron/` and `frontend/` directories)

```
pos_system/                         ← existing Laravel root (unchanged)
├── app/
├── routes/
├── ...
electron/                           ← NEW: Electron main process
│   ├── main.js                     ← Electron entry point
│   ├── preload.js                  ← Context bridge for IPC
│   ├── server-manager.js           ← PHP server lifecycle manager
│   ├── db-manager.js               ← MariaDB lifecycle manager
│   ├── updater.js                  ← Auto-updater (electron-updater)
│   └── package.json
frontend/                           ← NEW: Vue 3 SPA
│   ├── src/
│   │   ├── main.js
│   │   ├── App.vue
│   │   ├── router/
│   │   ├── stores/ (Pinia)
│   │   ├── api/   (Axios services)
│   │   ├── components/
│   │   └── views/
│   ├── vite.config.js
│   └── package.json
bin/                                ← NEW: Bundled binaries
│   ├── php/                        ← PHP 8.x binary (per platform)
│   └── mariadb/                    ← MariaDB binaries (per platform)
```

#### [NEW] `electron/package.json`
- `electron` ^28
- `electron-builder` (packaging)
- `electron-updater` (auto-updates)

#### [NEW] `frontend/package.json`
- `vue` ^3.4
- `vite` ^5
- `vue-router` ^4
- `pinia` ^2
- `axios` ^1.6
- `@vueuse/core`
- `tailwindcss` ^3 (fresh, no AdminLTE dependency)
- `chart.js` + `vue-chartjs`
- `@headlessui/vue` (modals, dropdowns)

---

### Phase 2 — Laravel API Layer Foundation

**Goal:** Add a proper versioned REST API to Laravel without breaking existing web routes.

#### [MODIFY] [routes/api.php](file:///Users/rickyperera/Documents/Projects/My%20Projects/pos_system/routes/api.php)

Replace the empty file with a versioned API structure:

```php
// All API routes under /api/v1/ prefix
Route::prefix('v1')->group(function () {
    // Public
    Route::post('/auth/login', [ApiAuthController::class, 'login']);
    Route::post('/auth/logout', [ApiAuthController::class, 'logout']);

    // Protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/user', [ApiAuthController::class, 'me']);
        Route::apiResource('products', Api\ProductApiController::class);
        Route::apiResource('contacts', Api\ContactApiController::class);
        Route::apiResource('transactions', Api\TransactionApiController::class);
        // ... all feature routes
    });
});
```

#### [NEW] API Controllers directory: `app/Http/Controllers/Api/`

Create dedicated API controllers that return JSON (no Blade, no session):

| Controller | Maps From | Key Methods |
|---|---|---|
| `ApiAuthController` | `Auth\LoginController` | `login`, `logout`, `me` |
| `Api\DashboardApiController` | `HomeController` | `getTotals`, `getStockAlert` |
| `Api\ProductApiController` | `ProductController` | CRUD + search + stock |
| `Api\VariationApiController` | `ProductController` | variation rows |
| `Api\ContactApiController` | `ContactController` | CRUD + ledger |
| `Api\TransactionApiController` | `SellController` | list, show, create |
| `Api\PosApiController` | `SellPosController` | create sale, get products, receipt |
| `Api\PurchaseApiController` | `PurchaseController` | CRUD + receive |
| `Api\PaymentApiController` | `TransactionPaymentController` | add/view payments |
| `Api\ReportApiController` | `ReportController` | all 30+ report endpoints |
| `Api\InventoryApiController` | `StockAdjustmentController` + `StockTransferController` | adjustments, transfers |
| `Api\AccountingApiController` | `AccountController` | accounts, transfers, balance sheet |
| `Api\CashRegisterApiController` | `CashRegisterController` | open/close register |
| `Api\BusinessApiController` | `BusinessController` | settings |
| `Api\UserApiController` | `ManageUserController` | users, roles |
| `Api\CategoryApiController` | `TaxonomyController` | categories |
| `Api\RestaurantApiController` | `Restaurant\*` | tables, kitchen, orders |
| `Api\ExpenseApiController` | `ExpenseController` | expenses |
| `Api\BarcodeApiController` | `BarcodeController` | barcode generation |

#### [NEW] API Resource classes: `app/Http/Resources/`

Laravel API Resources for standardized JSON output:

- `ProductResource` / `ProductCollection`
- `TransactionResource` / `TransactionCollection`
- `ContactResource` / `ContactCollection`
- `UserResource`
- `BusinessResource`
- `DashboardResource`
- etc.

#### [MODIFY] `config/auth.php`

Switch API guard to **Laravel Sanctum** (simpler than Passport for same-origin desktop app):

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'sanctum', 'provider' => 'users'],
],
```

> [!NOTE]
> Passport is already installed but Sanctum is lighter for a local desktop app. We'll keep Passport for any future public API and use Sanctum for the Electron frontend.

#### [MODIFY] `app/Http/Kernel.php`

Add Sanctum stateful middleware for the API group.

#### [NEW] `app/Http/Middleware/ApiAuth.php`

Unified API authentication middleware with proper JSON 401 responses.

---

### Phase 3 — Core API Endpoints (Authentication + Business Context)

**Goal:** Implement auth flow and business/user context APIs that the Vue app needs on startup.

#### Endpoints to implement:

```
POST   /api/v1/auth/login
       Body: { email, password }
       Returns: { token, user, business, permissions[], locations[], currency }

POST   /api/v1/auth/logout

GET    /api/v1/auth/me
       Returns: full user context (same as session data currently in SetSessionData middleware)

GET    /api/v1/business/settings
PUT    /api/v1/business/settings

GET    /api/v1/business/locations
GET    /api/v1/business/locations/{id}

GET    /api/v1/config
       Returns: currencies, tax rates, payment types, modules enabled — app bootstrap data
```

The login response must include everything the current `SetSessionData` middleware loads into session, because the Vue frontend needs it to render the app.

---

### Phase 4 — Product & Inventory API Endpoints

#### Endpoints:

```
GET    /api/v1/products                  (paginated, searchable, filterable)
POST   /api/v1/products
GET    /api/v1/products/{id}
PUT    /api/v1/products/{id}
DELETE /api/v1/products/{id}
GET    /api/v1/products/{id}/stock-history
GET    /api/v1/products/search?q=&location_id=   ← POS barcode/name search
GET    /api/v1/products/{variation_id}/pos-row   ← replaces getProductRow AJAX

GET    /api/v1/categories
GET    /api/v1/brands
GET    /api/v1/units
GET    /api/v1/tax-rates
GET    /api/v1/variation-templates

GET    /api/v1/stock/adjustment
POST   /api/v1/stock/adjustment
GET    /api/v1/stock/transfer
POST   /api/v1/stock/transfer
POST   /api/v1/stock/opening
```

---

### Phase 5 — Sales (POS) API Endpoints

This is the most critical module. The `SellPosController` (143KB) handles complex logic via `TransactionUtil` — all of this stays in PHP; the API just exposes it.

#### Endpoints:

```
GET    /api/v1/pos/config               ← All data needed to render POS screen
                                         (locations, customers, payment types, 
                                          tax rates, shortcuts, price groups)
GET    /api/v1/pos/products             ← Featured products for location
GET    /api/v1/pos/product/{variation_id}?location_id=
                                         ← Product row for cart (price, tax, stock)
POST   /api/v1/pos/sales                ← Create sale (replaces store() method)
PUT    /api/v1/pos/sales/{id}           ← Edit sale
GET    /api/v1/pos/sales/{id}/receipt   ← Returns receipt HTML or data
GET    /api/v1/pos/recent-transactions
GET    /api/v1/pos/reward-details?contact_id=

GET    /api/v1/sales                    ← Sales list (paginated)
GET    /api/v1/sales/{id}
GET    /api/v1/sales/drafts
GET    /api/v1/sales/quotations
POST   /api/v1/sales/{id}/duplicate
DELETE /api/v1/sales/{id}

GET    /api/v1/sell-returns
POST   /api/v1/sell-returns
```

---

### Phase 6 — Purchasing, Contacts & Payments API Endpoints

```
# Purchases
GET    /api/v1/purchases
POST   /api/v1/purchases
GET    /api/v1/purchases/{id}
PUT    /api/v1/purchases/{id}
GET    /api/v1/purchase-orders
POST   /api/v1/purchase-orders
POST   /api/v1/purchase-returns

# Contacts
GET    /api/v1/contacts                 (customers + suppliers, filterable)
POST   /api/v1/contacts
GET    /api/v1/contacts/{id}
PUT    /api/v1/contacts/{id}
DELETE /api/v1/contacts/{id}
GET    /api/v1/contacts/{id}/ledger
GET    /api/v1/contacts/{id}/payments
GET    /api/v1/contacts/customers       ← dropdown for POS

# Payments
GET    /api/v1/payments
POST   /api/v1/payments
GET    /api/v1/payments/{id}
POST   /api/v1/payments/pay-contact-due

# Expenses
GET    /api/v1/expenses
POST   /api/v1/expenses
GET    /api/v1/expense-categories

# Cash Register
GET    /api/v1/cash-register/current
POST   /api/v1/cash-register/open
POST   /api/v1/cash-register/close
GET    /api/v1/cash-register/details
```

---

### Phase 7 — Reports & Accounting API Endpoints

```
# Reports (30+ endpoints)
GET    /api/v1/reports/profit-loss
GET    /api/v1/reports/purchase-sell
GET    /api/v1/reports/stock
GET    /api/v1/reports/stock-details
GET    /api/v1/reports/stock-value
GET    /api/v1/reports/sale
GET    /api/v1/reports/purchase
GET    /api/v1/reports/tax
GET    /api/v1/reports/expense
GET    /api/v1/reports/register
GET    /api/v1/reports/trending-products
GET    /api/v1/reports/customer-supplier
GET    /api/v1/reports/activity-log
GET    /api/v1/reports/stock-expiry
# ... (all 30+ existing report routes)

# Accounting
GET    /api/v1/accounts
POST   /api/v1/accounts
GET    /api/v1/accounts/{id}
POST   /api/v1/accounts/fund-transfer
POST   /api/v1/accounts/deposit
GET    /api/v1/accounts/balance-sheet
GET    /api/v1/accounts/trial-balance
GET    /api/v1/accounts/cash-flow
```

---

### Phase 8 — Electron Shell

**Goal:** Build the Electron main process that starts the PHP server, manages the DB, and hosts the Vue app.

#### [NEW] `electron/main.js`

```javascript
// Core responsibilities:
// 1. Start MariaDB on app launch (port 3307)
// 2. Run database migrations if first run
// 3. Start PHP server: php artisan serve --port=8000
// 4. Open BrowserWindow loading Vue app (from built dist/ or Vite dev server)
// 5. Handle app lifecycle: graceful shutdown of PHP + MariaDB on quit
// 6. System tray integration
// 7. Native menu (File, View, Help)
// 8. IPC handlers for print, file open/save dialogs
```

#### [NEW] `electron/server-manager.js`

```javascript
// Manages PHP server lifecycle:
// - Spawns: bin/php/php artisan serve --host=127.0.0.1 --port=8000
// - Waits for server ready (polls /api/v1/health)
// - Restarts on crash
// - Pipes stdout/stderr to electron log file
```

#### [NEW] `electron/db-manager.js`

```javascript
// Manages MariaDB lifecycle:
// - Initializes MariaDB data directory on first run
// - Starts mysqld_safe with custom my.cnf
// - Creates database and user if not exists
// - Runs php artisan migrate on startup
// - Graceful shutdown on SIGTERM
```

#### [NEW] `electron/preload.js`

```javascript
// Context bridge exposing safe IPC to renderer:
// - window.electronAPI.printReceipt(html)   → triggers native print dialog
// - window.electronAPI.openBarcodeScanner() → USB/serial port access
// - window.electronAPI.getAppVersion()
// - window.electronAPI.openFileDialog()
// - window.electronAPI.showSaveDialog()
```

#### [NEW] `electron/package.json` (key config)

```json
{
  "main": "electron/main.js",
  "build": {
    "appId": "com.yourcompany.pos",
    "productName": "POS System",
    "directories": { "output": "dist-electron" },
    "files": ["electron/**", "frontend/dist/**", "pos_system/**", "bin/**"],
    "mac": { "target": "dmg", "arch": ["x64", "arm64"] },
    "win": { "target": "nsis" },
    "linux": { "target": "AppImage" },
    "extraResources": [
      { "from": "bin/", "to": "bin/" },
      { "from": "pos_system/", "to": "app/" }
    ]
  }
}
```

---

### Phase 9 — Vue 3 Frontend

**Goal:** Build the full SPA UI with Vue 3 + Pinia that mirrors all existing functionality.

#### [NEW] `frontend/src/router/index.js`

```javascript
// All routes mapped from existing web.php:
{ path: '/login', component: LoginView }
{ path: '/dashboard', component: DashboardView }
{ path: '/pos', component: PosView }           ← fullscreen POS
{ path: '/sales', component: SalesListView }
{ path: '/purchases', component: PurchasesView }
{ path: '/products', component: ProductsView }
{ path: '/contacts', component: ContactsView }
{ path: '/inventory', ... }
{ path: '/reports', ... }      ← nested routes per report type
{ path: '/accounting', ... }
{ path: '/settings', ... }
{ path: '/restaurant', ... }
```

#### [NEW] `frontend/src/stores/` (Pinia stores)

| Store | State |
|---|---|
| `authStore` | token, user, permissions, business, currency |
| `posStore` | cart items, active customer, payment lines, register |
| `productStore` | product cache, search results |
| `uiStore` | sidebar open, active location, loading states |
| `notificationStore` | unread count, notification list |

#### [NEW] `frontend/src/api/` (Axios service layer)

```javascript
// Base instance with token injection + error handling:
// api/client.js      ← axios instance, interceptors
// api/auth.js        ← login/logout/me
// api/products.js    ← product CRUD + search
// api/pos.js         ← POS-specific calls
// api/sales.js
// api/purchases.js
// api/contacts.js
// api/reports.js
// api/accounting.js
// api/inventory.js
// api/cashRegister.js
```

#### Key Views to Build

| View | Complexity | Notes |
|---|---|---|
| `LoginView` | Low | Simple form |
| `DashboardView` | Medium | Charts (Chart.js), summary cards |
| **`PosView`** | **Very High** | Cart, product search/grid, payment modal, receipt print |
| `ProductsView` | High | DataTable equiv, CRUD modals |
| `SalesListView` | Medium | Paginated list, filters |
| `PurchasesView` | High | CRUD with line items |
| `ContactsView` | Medium | Customers + Suppliers tabs |
| `ReportsView` | High | 30+ sub-views, filters, charts |
| `AccountingView` | High | Accounts, transactions, balance sheet |
| `InventoryView` | Medium | Stock adjustments, transfers |
| `SettingsView` | High | Business, locations, users, roles |
| `RestaurantView` | Medium | Tables, kitchen display |

#### POS Screen Design (most complex)

The Vue POS screen must replicate `sale_pos/create.blade.php` which currently handles:
- Product search (barcode + name, live)
- Cart management (qty, price, discount per line)
- Tax calculation
- Multiple payment methods
- Customer selection + credit limit check
- Reward points
- Receipt printing
- Keyboard shortcuts (replicate Mousetrap logic in Vue)

---

### Phase 10 — Printing & Receipt System

**Goal:** Handle thermal printer and PDF receipt printing natively in Electron.

#### Receipt Printing Strategy

| Method | Mechanism |
|---|---|
| **Browser print** | `window.electronAPI.printReceipt(html)` → `webContents.print()` |
| **Thermal printer** | Electron main process → node-escpos or raw TCP socket |
| **PDF download** | `webContents.printToPDF()` → save dialog |

#### [NEW] `electron/printer-manager.js`

Handles: printer discovery, ESC/POS command generation, raw socket printing (replaces Laravel's `PrinterController` + `printThis.js`).

The existing PHP `receiptContent()` method can still be called via API — it returns HTML which Electron then prints.

---

### Phase 11 — Packaging & Distribution

**Goal:** Create installable packages for macOS, Windows, Linux.

#### [NEW] `bin/` — Platform binaries

```
bin/
├── mac/
│   ├── php/php           ← PHP 8.2 for macOS (x64 + arm64 universal)
│   └── mariadb/          ← MariaDB 10.11 for macOS
├── win/
│   ├── php/php.exe
│   └── mariadb/mysqld.exe
└── linux/
    ├── php/php
    └── mariadb/mysqld
```

PHP binaries sourced from: [static-php-cli](https://github.com/crazywhalecc/static-php-cli)  
MariaDB: portable version from official builds.

#### Build commands

```bash
# Dev mode
npm run dev:electron    # starts Vite dev server + Electron (hot reload)

# Production build
npm run build:frontend  # vite build → frontend/dist/
npm run build:electron  # electron-builder → installers in dist-electron/
```

#### Auto-update

Using `electron-updater` with a self-hosted or GitHub Releases update server.

---

## Verification Plan

### Automated Tests

```bash
# Laravel API tests
php artisan test --filter=Api

# Vue unit tests (Vitest)
cd frontend && npm run test

# Electron integration tests (Playwright + Electron)
npx playwright test --project=electron
```

### Manual Verification Checkpoints

1. ✅ App launches, PHP server starts, DB connects
2. ✅ Login with existing credentials works
3. ✅ POS screen loads, product search works, sale completes end-to-end
4. ✅ Receipt prints (browser + thermal)
5. ✅ Purchase flow: create purchase → stock increases
6. ✅ Reports load with correct data
7. ✅ Offline operation (no internet) — all core features work
8. ✅ App quits cleanly (PHP + DB processes killed)
9. ✅ Auto-update downloads and installs

---

## Phased Delivery Timeline (Estimated)

| Phase | Description | Est. Effort |
|---|---|---|
| 1 | Project setup, monorepo, tooling | 1 week |
| 2 | Laravel API foundation (auth, Sanctum, resources) | 1.5 weeks |
| 3 | Auth + Business context APIs | 1 week |
| 4 | Product + Inventory APIs | 1.5 weeks |
| 5 | POS + Sales APIs (largest scope) | 3 weeks |
| 6 | Purchasing + Contacts + Payments APIs | 2 weeks |
| 7 | Reports + Accounting APIs | 2 weeks |
| 8 | Electron shell (main process, server + DB managers) | 2 weeks |
| 9 | Vue 3 frontend (all views) | 6–8 weeks |
| 10 | Printing system | 1 week |
| 11 | Packaging + distribution + auto-update | 1.5 weeks |
| — | **Total estimated effort** | **~22–25 weeks** |

> [!NOTE]
> Phases 2–7 (API layer) and Phase 8 (Electron shell) can be developed in parallel by separate team members. The Vue frontend (Phase 9) depends on Phases 2–7 being stable.

---

## Risk Register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Raw MySQL queries incompatible with MariaDB | Low | MariaDB is MySQL-compatible; test on MariaDB |
| Session-based middleware breaks API | Medium | API controllers skip session middleware; use token auth |
| License check (`pos_boot`) fails offline | High | Adapt to local license file before release |
| Complex Blade views hard to replicate in Vue | Medium | For most complex views (e.g., reports), phase in incrementally |
| PHP binary size (~20MB) + MariaDB (~150MB) = large installer | Medium | Explore SQLite migration in parallel to reduce bundle |
| Pusher real-time features unavailable offline | Medium | Use Laravel Reverb (local WebSocket) or polling |
| Keyboard shortcuts (Mousetrap) need Vue reimplementation | Low | Use `@vueuse/core` `useMagicKeys` |
