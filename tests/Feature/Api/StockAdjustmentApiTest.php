<?php

use App\Business;
use App\BusinessLocation;
use App\Product;
use App\Transaction;
use App\User;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `POST /api/v1/stock/adjustment` (Pest, task 4.4)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Index without `purchase.create` permission -> 403.
|   2. Index with permission -> 200, business-scoped paginated list.
|   3. Store with valid body -> 201, `transactions` row + `purchase_lines`
|      rows created, `variation_location_details.qty_available` decremented.
|   4. Store decrements stock by exactly the requested quantity (delta).
|   5. Store with a `location_id` from another business -> 422 with
|      `errors.location_id` (cross-business write blocked).
|   6. Store with an invalid `adjustment_type` -> 422.
|
| Bootstrap mirrors `ProductCrudTest.php` and adds the `business_locations`,
| `transactions`, `purchase_lines`, and `variation_location_details`
| schema this controller path mutates.
*/

beforeEach(function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('database.connections.mysql', config('database.connections.sqlite'));

    config()->set('session.driver', 'array');
    config()->set('sanctum.stateful', ['127.0.0.1:8000', 'localhost:8000']);

    DB::purge('sqlite');
    DB::purge('mysql');

    Schema::create('currencies', function ($t) {
        $t->increments('id');
        $t->string('country', 100);
        $t->string('currency', 100);
        $t->string('code', 25);
        $t->string('symbol', 25);
        $t->string('thousand_separator', 10)->nullable();
        $t->string('decimal_separator', 10)->nullable();
        $t->timestamps();
    });

    Schema::create('business', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('currency_id')->unsigned();
        $t->string('time_zone')->default('UTC');
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    Schema::create('users', function ($t) {
        $t->increments('id');
        $t->string('user_type')->default('user');
        $t->string('surname')->nullable();
        $t->string('first_name')->nullable();
        $t->string('last_name')->nullable();
        $t->string('username')->unique();
        $t->string('email')->nullable();
        $t->string('password');
        $t->string('language', 7)->default('en');
        $t->string('remember_token', 100)->nullable();
        $t->integer('business_id')->unsigned()->nullable();
        $t->string('status')->default('active');
        $t->boolean('allow_login')->default(true);
        $t->timestamp('deleted_at')->nullable();
        $t->timestamps();
    });

    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });

    // Spatie permission tables — needed so `Gate::before` returns null
    // and the test-defined gates take effect.
    Schema::create('permissions', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('roles', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('guard_name');
        $t->integer('business_id')->unsigned();
        $t->boolean('is_default')->default(0);
        $t->timestamps();
    });
    Schema::create('model_has_permissions', function ($t) {
        $t->integer('permission_id')->unsigned();
        $t->morphs('model');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function ($t) {
        $t->integer('role_id')->unsigned();
        $t->morphs('model');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function ($t) {
        $t->integer('permission_id')->unsigned();
        $t->integer('role_id')->unsigned();
        $t->primary(['permission_id', 'role_id']);
    });

    Schema::create('business_locations', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->boolean('is_active')->default(true);
        $t->string('receipt_printer_type')->default('browser');
        $t->timestamps();
    });

    Schema::create('products', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->string('type')->default('single');
        $t->string('sku')->nullable();
        $t->integer('unit_id')->unsigned();
        $t->boolean('enable_stock')->default(true);
        $t->boolean('not_for_selling')->default(false);
        $t->integer('created_by')->unsigned()->nullable();
        $t->timestamps();
    });

    Schema::create('variations', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('product_id')->unsigned();
        $t->integer('product_variation_id')->unsigned()->default(0);
        $t->string('sub_sku')->nullable();
        $t->decimal('default_purchase_price', 22, 4)->default(0);
        $t->decimal('default_sell_price', 22, 4)->default(0);
        $t->decimal('default_sell_price_inc_tax', 22, 4)->default(0);
        $t->softDeletes();
        $t->timestamps();
    });

    Schema::create('variation_location_details', function ($t) {
        $t->increments('id');
        $t->integer('product_id')->unsigned();
        $t->integer('variation_id')->unsigned();
        $t->integer('location_id')->unsigned();
        $t->decimal('qty_available', 22, 4)->default(0);
        $t->timestamps();
    });

    // `transactions` columns the controller writes to. The legacy table
    // has many more columns (~80) — this trims to what the API
    // store/index paths touch.
    Schema::create('transactions', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->integer('location_id')->unsigned();
        $t->string('type')->default('stock_adjustment');
        $t->string('status')->default('final');
        $t->string('payment_status')->nullable();
        $t->dateTime('transaction_date');
        $t->integer('contact_id')->unsigned()->nullable();
        $t->string('ref_no')->nullable();
        $t->string('adjustment_type')->nullable();
        $t->decimal('total_amount_recovered', 22, 4)->default(0);
        $t->decimal('shipping_charges', 22, 4)->default(0);
        $t->decimal('final_total', 22, 4)->default(0);
        $t->integer('transfer_parent_id')->unsigned()->nullable();
        $t->text('additional_notes')->nullable();
        $t->integer('created_by')->unsigned();
        $t->timestamps();
    });

    Schema::create('purchase_lines', function ($t) {
        $t->increments('id');
        $t->integer('transaction_id')->unsigned();
        $t->integer('product_id')->unsigned();
        $t->integer('variation_id')->unsigned();
        $t->decimal('quantity', 22, 4);
        $t->decimal('purchase_price', 22, 4)->default(0);
        $t->decimal('purchase_price_inc_tax', 22, 4)->default(0);
        $t->decimal('item_tax', 22, 4)->default(0);
        $t->timestamps();
    });

    config()->set('permission.cache.store', 'array');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $currencyId = DB::table('currencies')->insertGetId([
        'country' => 'United States',
        'currency' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ]);

    $business = Business::create([
        'name' => 'Acme POS',
        'currency_id' => $currencyId,
        'time_zone' => 'UTC',
        'is_active' => true,
    ]);

    $otherBusiness = Business::create([
        'name' => 'Rival POS',
        'currency_id' => $currencyId,
        'time_zone' => 'UTC',
        'is_active' => true,
    ]);

    $user = User::create([
        'user_type' => 'user',
        'surname' => 'Mr',
        'first_name' => 'Alice',
        'last_name' => 'Cashier',
        'username' => 'alice',
        'email' => 'alice@example.com',
        'password' => Hash::make('correct-horse-battery'),
        'business_id' => $business->id,
        'status' => 'active',
        'allow_login' => true,
    ]);

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    // Clear Eloquent's static `Model::$guardableColumns` cache (see
    // ProductCrudTest for the long-form rationale).
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // Two locations in Alice's business + one in the rival business.
    $aliceLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
    ]);
    $aliceLoc2 = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme Warehouse',
    ]);
    $rivalLoc = BusinessLocation::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Outlet',
    ]);

    // Seed a product + variation + an existing stock row at Alice's
    // primary location so we can verify decrement deltas.
    $product = Product::create([
        'business_id' => $business->id,
        'name' => 'Widget',
        'type' => 'single',
        'sku' => 'W-1',
        'unit_id' => 1,
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => $user->id,
    ]);
    $variation = Variation::create([
        'name' => 'DUMMY',
        'product_id' => $product->id,
        'sub_sku' => 'W-1-V',
    ]);
    VariationLocationDetails::create([
        'product_id' => $product->id,
        'variation_id' => $variation->id,
        'location_id' => $aliceLoc->id,
        'qty_available' => 50,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceLoc = $aliceLoc;
    test()->aliceLoc2 = $aliceLoc2;
    test()->rivalLoc = $rivalLoc;
    test()->product = $product;
    test()->variation = $variation;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

/**
 * Authenticate the seeded user via the live login endpoint so the
 * Sanctum stateful cookie flow + `SetSessionDataApi` middleware are
 * exercised end-to-end.
 */
function loginStockAdjustmentUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant `purchase.create` via Gate::define (legacy adjustment flow uses
 * this permission key — see the docblock on StockAdjustmentApiController).
 */
function grantStockAdjustmentPermissions(): void
{
    Gate::define('purchase.create', fn ($user) => true);
}

it('returns 403 when listing stock adjustments without purchase.create permission', function () {
    loginStockAdjustmentUser();

    $response = $this->getJson('/api/v1/stock/adjustment');

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'forbidden');
});

it('returns a 200 paginated list of stock adjustments scoped to the auth business', function () {
    grantStockAdjustmentPermissions();

    // Two adjustments in Alice's business, one in the rival business.
    Transaction::create([
        'business_id' => test()->business->id,
        'location_id' => test()->aliceLoc->id,
        'type' => 'stock_adjustment',
        'status' => 'final',
        'transaction_date' => now(),
        'ref_no' => 'SA-1',
        'adjustment_type' => 'normal',
        'final_total' => 10,
        'created_by' => test()->user->id,
    ]);
    Transaction::create([
        'business_id' => test()->business->id,
        'location_id' => test()->aliceLoc->id,
        'type' => 'stock_adjustment',
        'status' => 'final',
        'transaction_date' => now(),
        'ref_no' => 'SA-2',
        'adjustment_type' => 'abnormal',
        'final_total' => 20,
        'created_by' => test()->user->id,
    ]);
    Transaction::create([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'type' => 'stock_adjustment',
        'status' => 'final',
        'transaction_date' => now(),
        'ref_no' => 'RIV-1',
        'adjustment_type' => 'normal',
        'final_total' => 30,
        'created_by' => test()->user->id,
    ]);

    loginStockAdjustmentUser();

    $response = $this->getJson('/api/v1/stock/adjustment');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);

    expect($response->json('meta.total'))->toBe(2);

    $refs = collect($response->json('data'))->pluck('ref_no')->all();
    expect($refs)->toContain('SA-1');
    expect($refs)->toContain('SA-2');
    expect($refs)->not->toContain('RIV-1');
});

it('creates an adjustment and decrements stock atomically', function () {
    grantStockAdjustmentPermissions();
    loginStockAdjustmentUser();

    $startingQty = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    expect($startingQty)->toEqual(50.0);

    $response = $this->postJson('/api/v1/stock/adjustment', [
        'location_id' => test()->aliceLoc->id,
        'ref_no' => 'SA-NEW',
        'transaction_date' => '2024-01-15T10:00:00',
        'adjustment_type' => 'normal',
        'additional_notes' => 'Annual stock take',
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 7,
                'unit_price' => 4.5,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.type', 'stock_adjustment');
    $response->assertJsonPath('data.adjustment_type', 'normal');
    $response->assertJsonPath('data.ref_no', 'SA-NEW');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.location_id', test()->aliceLoc->id);
    $response->assertJsonPath('data.created_by', test()->user->id);

    $transactionId = $response->json('data.id');

    // The transaction row was persisted.
    $row = DB::table('transactions')->where('id', $transactionId)->first();
    expect($row)->not->toBeNull();
    expect($row->type)->toBe('stock_adjustment');
    expect((float) $row->final_total)->toEqual(7 * 4.5);

    // A purchase_lines row exists for this transaction.
    $lines = DB::table('purchase_lines')->where('transaction_id', $transactionId)->get();
    expect($lines)->toHaveCount(1);
    expect((int) $lines[0]->product_id)->toBe(test()->product->id);
    expect((int) $lines[0]->variation_id)->toBe(test()->variation->id);
    expect((float) $lines[0]->quantity)->toEqual(7.0);

    // Stock was decremented by exactly 7.
    $endingQty = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($endingQty)->toEqual(43.0);
});

it('decrements stock by exactly the requested quantity (delta check)', function () {
    grantStockAdjustmentPermissions();
    loginStockAdjustmentUser();

    $before = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->postJson('/api/v1/stock/adjustment', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-01-15T10:00:00',
        'adjustment_type' => 'abnormal',
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 12.25,
                'unit_price' => 1,
            ],
        ],
    ]);

    $response->assertStatus(201);

    $after = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    expect($before - $after)->toEqual(12.25);
});

it('rejects a stock adjustment posted against a location in a different business', function () {
    grantStockAdjustmentPermissions();
    loginStockAdjustmentUser();

    $response = $this->postJson('/api/v1/stock/adjustment', [
        'location_id' => test()->rivalLoc->id, // Cross-business write.
        'transaction_date' => '2024-01-15T10:00:00',
        'adjustment_type' => 'normal',
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 1,
                'unit_price' => 1,
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['location_id'],
    ]);

    // No transaction row was created.
    expect(DB::table('transactions')->count())->toBe(0);
});

it('rejects a stock adjustment with an invalid adjustment_type', function () {
    grantStockAdjustmentPermissions();
    loginStockAdjustmentUser();

    $response = $this->postJson('/api/v1/stock/adjustment', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-01-15T10:00:00',
        'adjustment_type' => 'bogus', // not in the in:normal,abnormal list
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 1,
                'unit_price' => 1,
            ],
        ],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['adjustment_type'],
    ]);

    expect(DB::table('transactions')->count())->toBe(0);
});
