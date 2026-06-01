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
use App\Http\Controllers\Api\StockAdjustmentApiController;
use App\Http\Controllers\Api\StockTransferApiController;
use App\Http\Controllers\Api\TaxRateApiController;
use App\Http\Controllers\Api\UnitApiController;
use App\Http\Controllers\Api\VariationTemplateApiController;
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
            });
        });

        // Product/contact/sale resources, etc. are added by tasks 3.x, 4.x,
        // 5.x, 6.x, 7.x. Product CRUD (task 4.1) is registered above
        // inside the `auth:sanctum` + `SetSessionDataApi` group.
    });

// The legacy `/user` placeholder route (Laravel default scaffold) is removed
// because it predates the /v1 versioning convention and does not match the
// /api/v1/auth/me endpoint that replaces it (task 2.3).
