# Implementation Plan: Desktop App Conversion (Option B)

## Overview

This plan breaks the 11 phases of `implementation_plan.md` into leaf tasks suitable for the
`spec-task-execution` subagent to execute one at a time. Each leaf task references the
requirements (`R<n>.<m>`) and correctness properties (`P<n>`) it validates and lists explicit
file paths and a test approach.

Conventions used throughout (see Notes section at the bottom):

- Property-based tests run **≥100 iterations** unless the task explicitly says otherwise.
- The error envelope returned by every `/api/v1/*` endpoint is
  `{ "message": String, "code": String, "errors": Map|null }`.
- PHP code is formatted with `vendor/bin/pint` and tested with `vendor/bin/pest`.
- TypeScript / Vue is formatted with `prettier`, linted with `eslint`, and tested with `vitest run`.
- Electron end-to-end tests run with `npx playwright test --project=electron`.

## Tasks

- [ ] 1. Project setup and repository structure

  - [ ] 1.1 Create `electron/` skeleton with `package.json` and entry stub
    - _Files:_ `electron/package.json`, `electron/main.js`, `electron/.eslintrc.cjs`, `electron/tsconfig.json`
    - _Test approach:_ run `npm install` and `npm run lint` inside `electron/`; manual smoke.
    - _Validates: R1.1_

  - [ ] 1.2 Create `frontend/` Vite + Vue 3 + Pinia skeleton
    - _Files:_ `frontend/package.json`, `frontend/vite.config.js`, `frontend/index.html`, `frontend/src/main.js`, `frontend/src/App.vue`, `frontend/tailwind.config.js`, `frontend/postcss.config.js`, `frontend/.eslintrc.cjs`, `frontend/.prettierrc`
    - _Test approach:_ run `npm run build` and `npm run test -- --run`; manual smoke.
    - _Validates: R1.1_

  - [ ] 1.3 Create `bin/win/` placeholder layout for bundled binaries
    - _Files:_ `bin/win/php/README.md`, `bin/win/mariadb/README.md`, `bin/win/.gitkeep`, `.gitignore`
    - _Test approach:_ manual smoke.
    - _Validates: R1.1, R1.2_

  - [ ] 1.4 Add root `package.json` with workspace orchestration scripts
    - _Files:_ `package.json`, `.editorconfig`, `README.md`
    - _Test approach:_ run `npm install` at the repo root, then `npm run lint`; manual smoke.
    - _Validates: R1.1_

- [ ] 2. Laravel API layer foundation

  - [ ] 2.1 Install and configure Laravel Sanctum
    - _Files:_ `composer.json`, `config/auth.php`, `config/sanctum.php`, `app/Http/Kernel.php`, `database/migrations/*_create_personal_access_tokens_table.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/SanctumBootstrapTest.php`.
    - _Validates: R10.1, R10.2, R10.6_

  - [ ] 2.2 Add `/api/v1` route group with `ApiHealthController`
    - _Files:_ `routes/api.php`, `app/Http/Controllers/Api/ApiHealthController.php`, `app/Exceptions/Handler.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ApiHealthTest.php`.
    - _Validates: R2.3, R8.1, R8.2_

  - [ ] 2.3 Implement `ApiAuthController` with login, logout, and `me`
    - _Files:_ `app/Http/Controllers/Api/ApiAuthController.php`, `app/Http/Requests/Api/LoginRequest.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/AuthFlowTest.php`.
    - _Validates: R10.1, R10.2, R10.3, R10.4, R10.5, R10.6_
    - _Property: P7_

  - [ ] 2.4 Define error envelope and JSON response macros
    - _Files:_ `app/Http/Responses/JsonError.php`, `app/Exceptions/Handler.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ErrorEnvelopeTest.php`.
    - _Validates: R8.2, R8.3, R10.4, R10.5_
    - _Property: P3_

  - [ ] 2.5 Create `SetSessionDataApi` middleware
    - _Files:_ `app/Http/Middleware/SetSessionDataApi.php`, `app/Http/Kernel.php`, `app/Http/api_helpers.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/SetSessionDataApiTest.php`.
    - _Validates: R8.4, R8.5_

  - [ ] 2.6 Implement `ApiBootstrapController::config`
    - _Files:_ `app/Http/Controllers/Api/ApiBootstrapController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test.
    - _Validates: R8.1, R8.2, R9.1, R9.5_

  - [ ] 2.7 Replace Laravel `pos_boot()` with a no-op stub
    - _Files:_ `app/Http/helpers.php`
    - _Test approach:_ Pest unit test `tests/Unit/Helpers/PosBootStubTest.php`.
    - _Validates: R7.3_

  - [ ] 2.8 Implement `Online_Guard` middleware
    - _Files:_ `app/Http/Middleware/OnlineGuard.php`, `app/Services/ConnectivityProbe.php`, `app/Http/Kernel.php`, `config/desktop.php`
    - _Test approach:_ Pest feature test using `Http::fake`.
    - _Validates: R12.2, R12.3_

  - [ ] 2.9 Implement `ModuleApiRouteProvider` for module auto-discovery
    - _Files:_ `app/Providers/ModuleApiRouteProvider.php`, `config/app.php`, `config/desktop.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ModuleAutoMountTest.php`.
    - _Validates: R9.1, R9.2, R9.3, R9.4_
    - _Property: P6 (PBT task 2.10 covers fuzzing)_

  - [ ] 2.10 Property test: module mounting completeness
    - _Files:_ `tests/Feature/Property/ModuleMountingPropertyTest.php`
    - _Test approach:_ Pest property-based test (≥100 iterations).
    - _Validates: R9.1, R9.2, R9.3_
    - _Property: P6_

  - [ ] 2.11 Property test: API JSON purity
    - _Files:_ `tests/Feature/Property/ApiJsonPurityPropertyTest.php`
    - _Test approach:_ Pest property-based test (≥100 iterations).
    - _Validates: R8.1, R8.2, R8.3, R8.4_
    - _Property: P3_

- [ ] 3. Auth and business-context APIs

  - [ ] 3.1 Implement `BusinessApiController` settings endpoints
    - _Files:_ `app/Http/Controllers/Api/BusinessApiController.php`, `app/Http/Resources/BusinessResource.php`, `routes/api.php`, `app/Http/Requests/Api/UpdateBusinessSettingsRequest.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/BusinessSettingsTest.php`.
    - _Validates: R8.1, R8.2, R8.4_

  - [ ] 3.2 Implement `BusinessLocationApiController`
    - _Files:_ `app/Http/Controllers/Api/BusinessLocationApiController.php`, `app/Http/Resources/BusinessLocationResource.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/BusinessLocationTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 3.3 Implement `UserResource` and per-user permission projection
    - _Files:_ `app/Http/Resources/UserResource.php`, `app/Http/Controllers/Api/ApiAuthController.php`
    - _Test approach:_ Pest snapshot test of the JSON shape.
    - _Validates: R10.2, R10.3_

  - [ ] 3.4 Pest property test: Sanctum cookie flow integrity (P7)
    - _Files:_ `tests/Feature/Property/SanctumCookieFlowPropertyTest.php`
    - _Test approach:_ Pest property-based test (≥100 iterations).
    - _Validates: R10.1, R10.2, R10.3, R10.4, R10.6_
    - _Property: P7_

- [ ] 4. Product and inventory APIs

  - [ ] 4.1 Implement `ProductApiController` CRUD
    - _Files:_ `app/Http/Controllers/Api/ProductApiController.php`, `app/Http/Resources/ProductResource.php`, `app/Http/Requests/Api/StoreProductRequest.php`, `app/Http/Requests/Api/UpdateProductRequest.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ProductCrudTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 4.2 Implement product search / pos-row endpoints
    - _Files:_ `app/Http/Controllers/Api/ProductApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ProductSearchTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 4.3 Implement `CategoryApiController`, `BrandApiController`, `UnitApiController`, `TaxRateApiController`, `VariationTemplateApiController`
    - _Files:_ `app/Http/Controllers/Api/CategoryApiController.php`, `app/Http/Controllers/Api/BrandApiController.php`, `app/Http/Controllers/Api/UnitApiController.php`, `app/Http/Controllers/Api/TaxRateApiController.php`, `app/Http/Controllers/Api/VariationTemplateApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature tests, one per controller.
    - _Validates: R8.1, R8.2_

  - [ ] 4.4 Implement stock adjustment and stock transfer endpoints
    - _Files:_ `app/Http/Controllers/Api/StockAdjustmentApiController.php`, `app/Http/Controllers/Api/StockTransferApiController.php`, `app/Http/Controllers/Api/OpeningStockApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature tests covering stock side effects.
    - _Validates: R8.1, R8.2_

- [ ] 5. POS and sales APIs (depends on 2.1–2.6)

  - [ ] 5.1 Implement `PosApiController::config` and `pos/products`
    - _Files:_ `app/Http/Controllers/Api/PosApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/PosConfigTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 5.2 Implement `POST /api/v1/pos/sales` (create sale)
    - _Files:_ `app/Http/Controllers/Api/PosApiController.php`, `app/Http/Requests/Api/StorePosSaleRequest.php`, `app/Http/Resources/PosSaleResource.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/PosSaleCreateTest.php`.
    - _Validates: R13.1_

  - [ ] 5.3 Implement `PUT /api/v1/pos/sales/{id}` (edit) and recent transactions
    - _Files:_ `app/Http/Controllers/Api/PosApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature tests.
    - _Validates: R8.1, R8.2_

  - [ ] 5.4 Implement `GET /api/v1/pos/sales/{id}/receipt` (Blade-rendered HTML in JSON envelope)
    - _Files:_ `app/Http/Controllers/Api/PosApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/PosReceiptRoundTripTest.php`.
    - _Validates: R13.2, R13.3, R13.6_
    - _Property: P5 (PBT task 5.6 covers fuzzing)_

  - [ ] 5.5 Implement `SaleApiController` (sales list, drafts, quotations, duplicate, destroy) and `SellReturnApiController`
    - _Files:_ `app/Http/Controllers/Api/SaleApiController.php`, `app/Http/Controllers/Api/SellReturnApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature tests.
    - _Validates: R8.1, R8.2_

  - [ ] 5.6 Pest property test: POS sale + receipt round-trip (P5)
    - _Files:_ `tests/Feature/Property/PosSaleReceiptRoundTripPropertyTest.php`
    - _Test approach:_ Pest property-based test (≥100 iterations).
    - _Validates: R13.1, R13.2, R13.3_
    - _Property: P5_

- [ ] 6. Purchasing, contacts, payments APIs (depends on 2.1–2.6)

  - [ ] 6.1 Implement `PurchaseApiController` and `PurchaseOrderApiController`
    - _Files:_ `app/Http/Controllers/Api/PurchaseApiController.php`, `app/Http/Controllers/Api/PurchaseOrderApiController.php`, `app/Http/Resources/PurchaseResource.php`, `app/Http/Requests/Api/StorePurchaseRequest.php`, `routes/api.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/PurchaseCrudTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 6.2 Implement `PurchaseReturnApiController`
    - _Files:_ `app/Http/Controllers/Api/PurchaseReturnApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test.
    - _Validates: R8.1, R8.2_

  - [ ] 6.3 Implement `ContactApiController` (customers + suppliers)
    - _Files:_ `app/Http/Controllers/Api/ContactApiController.php`, `app/Http/Resources/ContactResource.php`, `routes/api.php`, `app/Http/Requests/Api/StoreContactRequest.php`, `app/Http/Requests/Api/UpdateContactRequest.php`
    - _Test approach:_ Pest feature test `tests/Feature/Api/ContactCrudTest.php`.
    - _Validates: R8.1, R8.2_

  - [ ] 6.4 Implement `PaymentApiController` and `ExpenseApiController`
    - _Files:_ `app/Http/Controllers/Api/PaymentApiController.php`, `app/Http/Controllers/Api/ExpenseApiController.php`, `app/Http/Controllers/Api/ExpenseCategoryApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature tests.
    - _Validates: R8.1, R8.2_

  - [ ] 6.5 Implement `CashRegisterApiController`
    - _Files:_ `app/Http/Controllers/Api/CashRegisterApiController.php`, `routes/api.php`
    - _Test approach:_ Pest feature test.
    - _Validates: R8.1, R8.2_

- [ ] 7. Reports and accounting APIs (depends on 2.1–2.6)

  - [ ] 7.1 Implement `ReportApiController::profitLoss` and `purchaseSell`
    - _Files:_ `app/Http/Controllers/Api/ReportApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.2 Implement stock and stock-value reports
    - _Files:_ `app/Http/Controllers/Api/ReportApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.3 Implement sale and purchase reports
    - _Files:_ `app/Http/Controllers/Api/ReportApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.4 Implement register, trending products, customer/supplier, activity-log reports
    - _Files:_ `app/Http/Controllers/Api/ReportApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.5 Implement `AccountingApiController` (CRUD + transfers + deposits)
    - _Files:_ `app/Http/Controllers/Api/AccountingApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.6 Implement balance-sheet, trial-balance, cash-flow reports
    - _Files:_ `app/Http/Controllers/Api/AccountingApiController.php`, `routes/api.php`
    - _Validates: R8.1, R8.2_

  - [ ] 7.7 Module API exposure: parent task only
    - _Validates: R9.5_
    - _Property: P6_

    - [ ] 7.7.1 Add `Modules/Essentials/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.2 Add `Modules/Accounting/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.3 Add `Modules/AssetManagement/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.4 Add `Modules/Cms/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.5 Add `Modules/Connector/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.6 Add `Modules/Crm/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.7 Add `Modules/Ecommerce/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.8 Add `Modules/FieldForce/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.9 Add `Modules/Manufacturing/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.10 Add `Modules/ProductCatalogue/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.11 Add `Modules/Project/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.12 Add `Modules/Repair/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.13 Add `Modules/Spreadsheet/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.14 Add `Modules/Superadmin/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.15 Add `Modules/Woocommerce/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.16 Add `Modules/AiAssistance/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.17 Add `Modules/Hms/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.18 Add `Modules/InboxReport/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_
    - [ ] 7.7.19 Add `Modules/CustomDashboard/Routes/api.php` skeleton
      - _Validates: R9.2, R9.5_

  - [ ] 7.8 Checkpoint - Ensure all PHP API tests pass

- [ ] 8. Electron shell

  - [ ] 8.1 Implement `electron/main.js` boot orchestration
    - _Files:_ `electron/main.js`, `electron/ipc-handlers.js`
    - _Validates: R2.1, R2.2, R2.4, R2.5, R3.1, R4.1, R4.2, R4.3_

  - [ ] 8.2 Implement `electron/server-manager.js`
    - _Files:_ `electron/server-manager.js`
    - _Validates: R1.4, R2.2, R2.3, R2.7, R3.1, R3.2_

  - [ ] 8.3 Implement `electron/db-manager.js`
    - _Files:_ `electron/db-manager.js`
    - _Validates: R1.4, R2.1, R3.3, R3.5, R5.1, R5.2, R5.3, R5.4_

  - [ ] 8.4 Implement `electron/preload.js` context-bridge surface
    - _Files:_ `electron/preload.js`, `electron/ipc-handlers.js`
    - _Validates: R6.10, R13.4, R14.1, R14.2_

  - [ ] 8.5 Implement single-instance lock and window focus
    - _Files:_ `electron/main.js`
    - _Validates: R4.1, R4.2, R4.3_

  - [ ] 8.6 Implement startup-error fatal screen
    - _Files:_ `electron/error-window.js`, `electron/main.js`, `electron/error-window.html`
    - _Validates: R2.6, R2.7_

  - [ ] 8.7 Implement `electron/license-validator.js`
    - _Files:_ `electron/license-validator.js`, `electron/license-keys.js`
    - _Validates: R6.1, R6.2, R6.3, R6.4, R6.5, R6.6, R6.7, R6.8, R6.10, R6.11, R7.1, R7.2_

  - [ ] 8.8 fast-check property test: license signature verification (P1)
    - _Files:_ `electron/test/license-validator.signature.property.test.ts`, `electron/test/fixtures/license-keypair.ts`
    - _Test approach:_ fast-check property test (≥100 iterations).
    - _Validates: R6.1, R6.4, R6.5, R7.2_
    - _Property: P1_

  - [ ] 8.9 fast-check property test: license file tamper resistance (P10)
    - _Files:_ `electron/test/license-validator.tamper.property.test.ts`
    - _Test approach:_ fast-check property test (≥100 iterations).
    - _Validates: R7.1, R7.2, R6.5_
    - _Property: P10_

  - [ ] 8.10 Implement `electron/updater.js` (`electron-updater` integration)
    - _Files:_ `electron/updater.js`, `electron/main.js`
    - _Validates: R15.1, R15.2, R15.3, R15.4, R15.5_

  - [ ] 8.11 Integration smoke: process supervision invariant (P4)
    - _Files:_ `electron/test/supervision.integration.test.ts`
    - _Validates: R2.1, R2.2, R2.6, R2.7_
    - _Property: P4 (integration, not PBT)_

  - [ ] 8.12 Integration smoke: graceful shutdown (P9)
    - _Files:_ `electron/test/shutdown.integration.test.ts`
    - _Validates: R3.1, R3.2, R3.3, R3.4, R3.5_
    - _Property: P9 (integration, not PBT)_

  - [ ] 8.13 Integration smoke: first-run DB idempotence (P8)
    - _Files:_ `electron/test/db-idempotence.integration.test.ts`
    - _Validates: R5.1, R5.2, R5.3_
    - _Property: P8 (integration, not PBT)_

  - [ ] 8.14 Checkpoint - Ensure Electron tests pass

- [ ] 9. Vue 3 frontend (depends on Phases 2–7 for APIs and Phase 8 for `electronAPI`)

  - [ ] 9.1 Implement `frontend/src/api/client.js` Axios instance
    - _Files:_ `frontend/src/api/client.js`
    - _Validates: R10.5, R10.4_

  - [ ] 9.2 Implement `authStore` (Pinia) and `/sanctum/csrf-cookie` flow
    - _Files:_ `frontend/src/stores/auth.js`, `frontend/src/api/auth.js`
    - _Validates: R10.1, R10.2, R10.3, R10.4_

  - [ ] 9.3 Implement `connectivityStore` (Pinia) with heartbeat + debounce
    - _Files:_ `frontend/src/stores/connectivity.js`
    - _Validates: R11.1, R11.2, R11.3_

  - [ ] 9.4 fast-check property test: connectivity indicator consistency (P11)
    - _Files:_ `frontend/src/stores/connectivity.property.test.ts`
    - _Test approach:_ fast-check property test (≥100 iterations).
    - _Validates: R11.3, R11.4, R11.5, R11.6_
    - _Property: P11_

  - [ ] 9.5 Implement `App.vue` shell with global "Offline" indicator
    - _Files:_ `frontend/src/App.vue`, `frontend/src/components/OnlineOnlyButton.vue`, `frontend/src/components/ConnectivityIndicator.vue`
    - _Validates: R11.4, R11.5, R11.6_

  - [ ] 9.6 Implement Vue Router with auth + license guards
    - _Files:_ `frontend/src/router/index.js`, `frontend/src/stores/license.js`
    - _Validates: R6.9, R10.4_

  - [ ] 9.7 Implement `LoginView`
    - _Files:_ `frontend/src/views/LoginView.vue`
    - _Validates: R10.1, R10.2_

  - [ ] 9.8 Implement `LicenseView`
    - _Files:_ `frontend/src/views/LicenseView.vue`
    - _Validates: R6.9, R6.10, R6.11_

  - [ ] 9.9 Implement `DashboardView`
    - _Files:_ `frontend/src/views/DashboardView.vue`, `frontend/src/api/dashboard.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.10 Implement `PosView`
    - _Files:_ `frontend/src/views/PosView.vue`, `frontend/src/stores/pos.js`, `frontend/src/api/pos.js`, `frontend/src/components/pos/Cart.vue`, `frontend/src/components/pos/ProductGrid.vue`, `frontend/src/components/pos/PaymentModal.vue`
    - _Validates: R13.1, R13.2, R13.4_

  - [ ] 9.11 Implement `ProductsView` and CRUD modals
    - _Files:_ `frontend/src/views/ProductsView.vue`, `frontend/src/api/products.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.12 Implement `SalesListView`
    - _Files:_ `frontend/src/views/SalesListView.vue`, `frontend/src/api/sales.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.13 Implement `PurchasesView`
    - _Files:_ `frontend/src/views/PurchasesView.vue`, `frontend/src/api/purchases.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.14 Implement `ContactsView`
    - _Files:_ `frontend/src/views/ContactsView.vue`, `frontend/src/api/contacts.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.15 Implement `InventoryView`
    - _Files:_ `frontend/src/views/InventoryView.vue`, `frontend/src/api/inventory.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.16 Implement `ReportsView` (parent + nested sub-views)
    - _Files:_ `frontend/src/views/reports/*.vue`, `frontend/src/api/reports.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.17 Implement `AccountingView`
    - _Files:_ `frontend/src/views/AccountingView.vue`, `frontend/src/api/accounting.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.18 Implement `SettingsView` (business, locations, users, roles, printer)
    - _Files:_ `frontend/src/views/SettingsView.vue`, `frontend/src/api/users.js`, `frontend/src/api/business.js`
    - _Validates: R8.1, R8.2, R14.5_

  - [ ] 9.19 Implement `RestaurantView`
    - _Files:_ `frontend/src/views/RestaurantView.vue`, `frontend/src/api/restaurant.js`
    - _Validates: R8.1, R8.2_

  - [ ] 9.20 Implement offline gating in API services
    - _Files:_ `frontend/src/api/online.js`, `frontend/src/api/payments.js`, `frontend/src/api/sms.js`
    - _Validates: R12.1, R12.4, R12.5_
    - _Property: P2 (covered by Vitest unit tests)_

  - [ ] 9.21 Integration smoke: offline call gating end-to-end (P2)
    - _Files:_ `electron/test/offline-gating.integration.test.ts`
    - _Validates: R12.1, R12.2, R12.4, R12.5_
    - _Property: P2 (integration smoke counterpart to 9.20)_

  - [ ] 9.22 Checkpoint - Ensure Vue tests pass

- [ ] 10. Printing and receipt system (depends on 5.4 and 8.4)

  - [ ] 10.1 Implement `electron/printer-manager.js`
    - _Files:_ `electron/printer-manager.js`
    - _Validates: R13.4, R13.5, R14.1, R14.2_

  - [ ] 10.2 Wire `printReceipt` IPC and PDF fallback in renderer
    - _Files:_ `electron/preload.js`, `electron/ipc-handlers.js`, `frontend/src/components/pos/PrintFailureToast.vue`, `frontend/src/views/PosView.vue`, `frontend/src/views/SalesListView.vue`
    - _Validates: R14.1, R14.2, R14.3, R14.4_

  - [ ] 10.3 Per-location printer target setting persistence
    - _Files:_ `database/migrations/<ts>_add_printer_target_to_business_locations.php`, `app/Http/Controllers/Api/BusinessLocationApiController.php`, `frontend/src/views/SettingsView.vue`
    - _Validates: R14.5_

  - [ ] 10.4 Integration smoke: receipt round-trip end-to-end
    - _Files:_ `electron/test/receipt-roundtrip.integration.test.ts`
    - _Validates: R13.4, R13.5_
    - _Property: P5 (PBT side covered in 5.6; this is the IPC/printer integration side)_

- [ ] 11. Packaging and distribution (depends on EVERY prior phase)

  - [ ] 11.1 Configure `electron-builder` for Windows NSIS
    - _Files:_ `electron/package.json`
    - _Validates: R1.1, R1.2_

  - [ ] 11.2 Add NSIS installer hooks for `%APPDATA%/POSSystem/`
    - _Files:_ `electron/installer/installer.nsh`, `electron/package.json`
    - _Validates: R1.3, R1.4_

  - [ ] 11.3 Configure auto-update feed and code-signing
    - _Files:_ `electron/build/dev-app-update.yml`, `electron/package.json`, `electron/build/cert-pin.js`
    - _Validates: R15.1, R15.2_

  - [ ] 11.4 Add CI workflow for Windows build + signed installer
    - _Files:_ `.github/workflows/desktop-windows.yml`
    - _Validates: R1.1, R1.2_

  - [ ] 11.5 End-to-end packaging smoke
    - _Files:_ `scripts/smoke/package-smoke.ps1`
    - _Validates: R1.1, R1.2, R1.3_

  - [ ] 11.6 Final checkpoint - all tests and packaging pass

## Notes

- **Property-based test iteration count**: every PBT task in this plan runs **≥100 iterations**.
- **Error envelope shape**: every `/api/v1/*` error response returns the canonical envelope
  `{"message": String, "code": String, "errors": Map<String, List<String>>|null}`.
  Codes used: `validation_failed` (422), `unauthenticated` (401), `forbidden` (403),
  `not_found` (404), `csrf_mismatch` (419), `offline_required` (503), `server_error` (500).
- **Format / lint commands**:
  - PHP: `vendor/bin/pint` (format), `composer test` (Pest with deprecation suppression).
  - Electron: `npm run lint --workspace electron` (eslint), `npm run test --workspace electron`
    (vitest), `npx playwright test --project=electron`.
  - Frontend: `npm run lint --workspace frontend` (eslint + prettier),
    `npm run test --workspace frontend -- --run` (vitest).
- **PBT vs integration**: per the design's Testing Strategy, properties P2, P4, P8, P9 are
  expressed as **integration smoke** tasks rather than PBT tasks because they involve
  real OS process supervision and on-disk state. Properties P1, P3, P5, P6, P7, P10, P11 are
  expressed as PBT tasks.
