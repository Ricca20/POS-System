<?php

use App\Http\Controllers\Api\ApiAuthController;
use App\Http\Controllers\Api\ApiBootstrapController;
use App\Http\Controllers\Api\ApiHealthController;
use App\Http\Controllers\Api\BrandApiController;
use App\Http\Controllers\Api\BusinessApiController;
use App\Http\Controllers\Api\BusinessLocationApiController;
use App\Http\Controllers\Api\CategoryApiController;
use App\Http\Controllers\Api\OpeningStockApiController;
use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\Api\ProductApiController;
use App\Http\Controllers\Api\SaleApiController;
use App\Http\Controllers\Api\SellReturnApiController;
use App\Http\Controllers\Api\StockAdjustmentApiController;
use App\Http\Controllers\Api\StockTransferApiController;
use App\Http\Controllers\Api\TaxRateApiController;
use App\Http\Controllers\Api\UnitApiController;
use App\Http\Controllers\Api\VariationTemplateApiController;
use App\Http\Controllers\Api\PurchaseApiController;
use App\Http\Controllers\Api\PurchaseOrderApiController;
use App\Http\Controllers\Api\PurchaseReturnApiController;
use App\Http\Controllers\Api\ContactApiController;
use App\Http\Controllers\Api\PaymentApiController;
use App\Http\Controllers\Api\ExpenseApiController;
use App\Http\Controllers\Api\ExpenseCategoryApiController;
use App\Http\Controllers\Api\CashRegisterApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\AccountingApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Versioned JSON API for the desktop SPA
|--------------------------------------------------------------------------
|
| All routes under /api/v1/* are JSON-only and never render Blade
| (Requirement R8.1, R8.2, R8.3). Authentication uses Sanctum stateful
| (R10.6). Throttling is set to 300 req/min per IP for now; tune later.
|
*/
Route::prefix('v1')
    ->middleware(['api', 'throttle:300,1'])
    ->group(function () {
        // Public — used by Electron's server-manager to detect server-ready (R2.3).
        Route::get('/health', [ApiHealthController::class, 'ping'])
            ->name('api.v1.health');

        // Public auth endpoint: SPA hits /sanctum/csrf-cookie first, then this
        // (R10.1, R10.2, R10.5).
        Route::post('/auth/login', [ApiAuthController::class, 'login'])
            ->name('api.v1.auth.login');

        // Protected auth endpoints (R10.3, R10.4).
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/auth/me', [ApiAuthController::class, 'me'])
                ->name('api.v1.auth.me');

            Route::post('/auth/logout', [ApiAuthController::class, 'logout'])
                ->name('api.v1.auth.logout');

            // Business-context-aware endpoints. `SetSessionDataApi` populates
            // the request-scoped `pos.context` container so controllers can
            // read business/user/permission data via `pos_context()` without
            // touching the PHP session (R8.4, R8.5).
            Route::middleware('SetSessionDataApi')->group(function () {
                // Bootstrap (task 2.6, R8.1, R8.2, R9.1, R9.5).
                Route::get('/config', [ApiBootstrapController::class, 'config'])
                    ->name('api.v1.config');

                // Business settings (task 3.1, R8.1, R8.2, R8.4).
                Route::get('/business/settings', [BusinessApiController::class, 'show'])
                    ->name('api.v1.business.settings.show');
                Route::put('/business/settings', [BusinessApiController::class, 'update'])
                    ->name('api.v1.business.settings.update');

                // Business locations (task 3.2, R8.1, R8.2). Read-only:
                // every query is scoped to `pos_context('business.id')`
                // so cross-business reads are impossible.
                Route::get('/business/locations', [BusinessLocationApiController::class, 'index'])
                    ->name('api.v1.business.locations.index');
                Route::get('/business/locations/{id}', [BusinessLocationApiController::class, 'show'])
                    ->name('api.v1.business.locations.show')
                    ->whereNumber('id');

                // Product search/pos-row (task 4.2, R8.1, R8.2). Both
                // paths are registered BEFORE the apiResource below so
                // Laravel's router matches `/products/search` to the
                // dedicated method rather than treating "search" as the
                // `{product}` parameter on the apiResource's show route.
                Route::get('/products/search', [ProductApiController::class, 'search'])
                    ->name('api.v1.products.search');
                Route::get('/products/{variation_id}/pos-row', [ProductApiController::class, 'posRow'])
                    ->name('api.v1.products.pos-row')
                    ->whereNumber('variation_id');

                // Product CRUD (task 4.1, R8.1, R8.2). Index/show/destroy
                // permission gating is inline in the controller; store/update
                // are gated inside their dedicated FormRequests so a 403
                // short-circuits before validation runs.
                Route::apiResource('products', ProductApiController::class)
                    ->only(['index', 'show', 'store', 'update', 'destroy']);

                // Read-only taxonomy endpoints (task 4.3, R8.1, R8.2).
                // These are dropdown sources for the SPA — auth:sanctum
                // is sufficient gating; no per-permission check is
                // applied (see each controller's class doc).
                Route::get('/categories', [CategoryApiController::class, 'index'])
                    ->name('api.v1.categories.index');
                Route::get('/categories/{id}', [CategoryApiController::class, 'show'])
                    ->name('api.v1.categories.show')
                    ->whereNumber('id');

                Route::get('/brands', [BrandApiController::class, 'index'])
                    ->name('api.v1.brands.index');
                Route::get('/brands/{id}', [BrandApiController::class, 'show'])
                    ->name('api.v1.brands.show')
                    ->whereNumber('id');

                Route::get('/units', [UnitApiController::class, 'index'])
                    ->name('api.v1.units.index');
                Route::get('/units/{id}', [UnitApiController::class, 'show'])
                    ->name('api.v1.units.show')
                    ->whereNumber('id');

                Route::get('/tax-rates', [TaxRateApiController::class, 'index'])
                    ->name('api.v1.tax-rates.index');
                Route::get('/tax-rates/{id}', [TaxRateApiController::class, 'show'])
                    ->name('api.v1.tax-rates.show')
                    ->whereNumber('id');

                Route::get('/variation-templates', [VariationTemplateApiController::class, 'index'])
                    ->name('api.v1.variation-templates.index');
                Route::get('/variation-templates/{id}', [VariationTemplateApiController::class, 'show'])
                    ->name('api.v1.variation-templates.show')
                    ->whereNumber('id');

                // Stock adjustment, stock transfer, and opening stock
                // endpoints (task 4.4, R8.1, R8.2). All three mutate
                // `variation_location_details.qty_available`; adjustment
                // and transfer additionally create paired `transactions`
                // and `purchase_lines` rows. Each store flow is wrapped
                // in a `DB::transaction` for atomicity.
                Route::get('/stock/adjustment', [StockAdjustmentApiController::class, 'index'])
                    ->name('api.v1.stock.adjustment.index');
                Route::post('/stock/adjustment', [StockAdjustmentApiController::class, 'store'])
                    ->name('api.v1.stock.adjustment.store');
                Route::get('/stock/transfer', [StockTransferApiController::class, 'index'])
                    ->name('api.v1.stock.transfer.index');
                Route::post('/stock/transfer', [StockTransferApiController::class, 'store'])
                    ->name('api.v1.stock.transfer.store');
                Route::post('/stock/opening', [OpeningStockApiController::class, 'store'])
                    ->name('api.v1.stock.opening.store');

                // POS-screen bootstrap (task 5.1, R8.1, R8.2). `config`
                // returns the full POS payload (locations + featured
                // products IDs, payment types, tax rates, walk-in
                // customer, keyboard shortcuts, POS settings, selling-
                // price groups). `products` returns the featured-
                // products grid for a single location, scoped to the
                // active business.
                Route::get('/pos/config', [PosApiController::class, 'config'])
                    ->name('api.v1.pos.config');
                Route::get('/pos/products', [PosApiController::class, 'products'])
                    ->name('api.v1.pos.products');
                Route::post('/pos/sales', [PosApiController::class, 'store'])
                    ->name('api.v1.pos.sales.store');
                Route::put('/pos/sales/{id}', [PosApiController::class, 'update'])
                    ->name('api.v1.pos.sales.update')
                    ->whereNumber('id');
                Route::get('/pos/sales/{id}/receipt', [PosApiController::class, 'receipt'])
                    ->name('api.v1.pos.sales.receipt')
                    ->whereNumber('id');
                Route::get('/pos/recent-transactions', [PosApiController::class, 'recentTransactions'])
                    ->name('api.v1.pos.recent-transactions');
                Route::get('/pos/reward-details', [PosApiController::class, 'rewardDetails'])
                    ->name('api.v1.pos.reward-details');

                // Sales management endpoints (task 5.5, R8.1, R8.2).
                // CRITICAL ORDERING: `/sales/drafts` and
                // `/sales/quotations` MUST be registered BEFORE
                // `/sales/{id}` so Laravel's router matches the literal
                // segments first. If the parameterized route were
                // registered first, Laravel would treat the literal
                // "drafts" / "quotations" path tokens as `{id}` values
                // and bind them to `show()`, which would 404 on a
                // string id rather than dispatching to the dedicated
                // list endpoints.
                Route::get('/sales/drafts', [SaleApiController::class, 'drafts'])
                    ->name('api.v1.sales.drafts');
                Route::get('/sales/quotations', [SaleApiController::class, 'quotations'])
                    ->name('api.v1.sales.quotations');
                Route::get('/sales', [SaleApiController::class, 'index'])
                    ->name('api.v1.sales.index');
                Route::get('/sales/{id}', [SaleApiController::class, 'show'])
                    ->name('api.v1.sales.show')
                    ->whereNumber('id');
                Route::post('/sales/{id}/duplicate', [SaleApiController::class, 'duplicate'])
                    ->name('api.v1.sales.duplicate')
                    ->whereNumber('id');
                Route::delete('/sales/{id}', [SaleApiController::class, 'destroy'])
                    ->name('api.v1.sales.destroy')
                    ->whereNumber('id');

                // Sell-return endpoints (task 5.5, R8.1, R8.2).
                Route::get('/sell-returns', [SellReturnApiController::class, 'index'])
                    ->name('api.v1.sell-returns.index');
                Route::post('/sell-returns', [SellReturnApiController::class, 'store'])
                    ->name('api.v1.sell-returns.store');

                // --- Phase 6 routes ---

                // Purchases
                Route::get('/purchases', [PurchaseApiController::class, 'index'])->name('api.v1.purchases.index');
                Route::post('/purchases', [PurchaseApiController::class, 'store'])->name('api.v1.purchases.store');
                Route::get('/purchases/{id}', [PurchaseApiController::class, 'show'])->name('api.v1.purchases.show')->whereNumber('id');
                Route::put('/purchases/{id}', [PurchaseApiController::class, 'update'])->name('api.v1.purchases.update')->whereNumber('id');
                Route::delete('/purchases/{id}', [PurchaseApiController::class, 'destroy'])->name('api.v1.purchases.destroy')->whereNumber('id');
                Route::get('/purchase-orders', [PurchaseOrderApiController::class, 'index'])->name('api.v1.purchase-orders.index');
                Route::post('/purchase-orders', [PurchaseOrderApiController::class, 'store'])->name('api.v1.purchase-orders.store');
                Route::post('/purchase-returns', [PurchaseReturnApiController::class, 'store'])->name('api.v1.purchase-returns.store');

                // Contacts (literal segments first)
                Route::get('/contacts/customers', [ContactApiController::class, 'customers'])->name('api.v1.contacts.customers');
                Route::get('/contacts', [ContactApiController::class, 'index'])->name('api.v1.contacts.index');
                Route::post('/contacts', [ContactApiController::class, 'store'])->name('api.v1.contacts.store');
                Route::get('/contacts/{id}', [ContactApiController::class, 'show'])->name('api.v1.contacts.show')->whereNumber('id');
                Route::put('/contacts/{id}', [ContactApiController::class, 'update'])->name('api.v1.contacts.update')->whereNumber('id');
                Route::delete('/contacts/{id}', [ContactApiController::class, 'destroy'])->name('api.v1.contacts.destroy')->whereNumber('id');
                Route::get('/contacts/{id}/ledger', [ContactApiController::class, 'ledger'])->name('api.v1.contacts.ledger')->whereNumber('id');
                Route::get('/contacts/{id}/payments', [ContactApiController::class, 'payments'])->name('api.v1.contacts.payments')->whereNumber('id');

                // Payments
                Route::get('/payments', [PaymentApiController::class, 'index'])->name('api.v1.payments.index');
                Route::post('/payments', [PaymentApiController::class, 'store'])->name('api.v1.payments.store');
                Route::get('/payments/{id}', [PaymentApiController::class, 'show'])->name('api.v1.payments.show')->whereNumber('id');
                Route::post('/payments/pay-contact-due', [PaymentApiController::class, 'payContactDue'])->name('api.v1.payments.pay-contact-due');

                // Expenses
                Route::get('/expenses', [ExpenseApiController::class, 'index'])->name('api.v1.expenses.index');
                Route::post('/expenses', [ExpenseApiController::class, 'store'])->name('api.v1.expenses.store');
                Route::put('/expenses/{id}', [ExpenseApiController::class, 'update'])->name('api.v1.expenses.update')->whereNumber('id');
                Route::delete('/expenses/{id}', [ExpenseApiController::class, 'destroy'])->name('api.v1.expenses.destroy')->whereNumber('id');
                Route::get('/expense-categories', [ExpenseCategoryApiController::class, 'index'])->name('api.v1.expense-categories.index');

                // Cash Register
                Route::get('/cash-register/current', [CashRegisterApiController::class, 'current'])->name('api.v1.cash-register.current');
                Route::post('/cash-register/open', [CashRegisterApiController::class, 'open'])->name('api.v1.cash-register.open');
                Route::post('/cash-register/close', [CashRegisterApiController::class, 'close'])->name('api.v1.cash-register.close');
                Route::get('/cash-register/details', [CashRegisterApiController::class, 'details'])->name('api.v1.cash-register.details');

                // --- Phase 7 routes ---

                // Reports
                Route::prefix('reports')->name('api.v1.reports.')->group(function () {
                    Route::get('/profit-loss', [ReportApiController::class, 'profit_loss'])->name('profit-loss');
                    Route::get('/purchase-sell', [ReportApiController::class, 'purchase_sell'])->name('purchase-sell');
                    Route::get('/stock', [ReportApiController::class, 'stock_report'])->name('stock');
                    Route::get('/stock-details', [ReportApiController::class, 'stock_details'])->name('stock-details');
                    Route::get('/trending-products', [ReportApiController::class, 'trending_products'])->name('trending-products');
                    Route::get('/tax', [ReportApiController::class, 'tax_report'])->name('tax');
                    Route::get('/expense', [ReportApiController::class, 'expense_report'])->name('expense');
                    Route::get('/register', [ReportApiController::class, 'register_report'])->name('register');
                    Route::get('/sales-representative', [ReportApiController::class, 'sales_representative_report'])->name('sales-representative');
                    Route::get('/stock-expiry', [ReportApiController::class, 'stock_expiry_report'])->name('stock-expiry');
                    Route::get('/customer-supplier', [ReportApiController::class, 'customer_supplier'])->name('customer-supplier');
                    Route::get('/activity-log', [ReportApiController::class, 'activity_log'])->name('activity-log');
                });

                // Accounting
                Route::prefix('accounts')->name('api.v1.accounts.')->group(function () {
                    Route::get('/', [AccountingApiController::class, 'index'])->name('index');
                    Route::post('/', [AccountingApiController::class, 'store'])->name('store');
                    Route::get('/cash-flow', [AccountingApiController::class, 'cash_flow'])->name('cash-flow');
                    Route::post('/fund-transfer', [AccountingApiController::class, 'fund_transfer'])->name('fund-transfer');
                    Route::post('/deposit', [AccountingApiController::class, 'deposit'])->name('deposit');
                    Route::get('/{id}', [AccountingApiController::class, 'show'])->name('show');
                    Route::put('/{id}', [AccountingApiController::class, 'update'])->name('update');
                    Route::post('/{id}/close', [AccountingApiController::class, 'close'])->name('close');
                });
            });
        });

        // Product/contact/sale resources, etc. are added by tasks 3.x, 4.x,
        // 5.x, 6.x, 7.x. Product CRUD (task 4.1) is registered above
        // inside the `auth:sanctum` + `SetSessionDataApi` group.
    });

// The legacy `/user` placeholder route (Laravel default scaffold) is removed
// because it predates the /v1 versioning convention and does not match the
// /api/v1/auth/me endpoint that replaces it (task 2.3).
