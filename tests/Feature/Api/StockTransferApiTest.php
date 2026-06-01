<?php

use App\Business;
use App\BusinessLocation;
use App\Product;
use App\User;
use App\Variation;
use App\VariationLocationDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `POST /api/v1/stock/transfer` (Pest, task 4.4)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Store with valid body -> 201, source `qty_available` decreases AND
|      destination `qty_available` increases by exactly the same amount.
|   2. `from_location_id == to_location_id` -> 422 (validation rejects
|      same-source-and-destination transfers).
|   3. Both `sell_transfer` and `purchase_transfer` Transaction rows are
|      created, paired via `transfer_parent_id`. Two `purchase_lines` rows
|      are created (one per transaction).
|
| Bootstrap mirrors `StockAdjustmentApiTest.php`.
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

    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    $fromLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
    ]);
    $toLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme Warehouse',
    ]);

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
    // Source location starts with 30; destination has no row at all
    // (so the test verifies the upsert-on-missing path).
    VariationLocationDetails::create([
        'product_id' => $product->id,
        'variation_id' => $variation->id,
        'location_id' => $fromLoc->id,
        'qty_available' => 30,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->fromLoc = $fromLoc;
    test()->toLoc = $toLoc;
    test()->product = $product;
    test()->variation = $variation;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginStockTransferUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantStockTransferPermissions(): void
{
    Gate::define('purchase.create', fn ($user) => true);
}

it('moves stock from source to destination location atomically', function () {
    grantStockTransferPermissions();
    loginStockTransferUser();

    $beforeFrom = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->fromLoc->id)
        ->value('qty_available');
    expect($beforeFrom)->toEqual(30.0);

    // Destination has no row yet — the controller should upsert.
    $beforeToRow = DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->toLoc->id)
        ->first();
    expect($beforeToRow)->toBeNull();

    $response = $this->postJson('/api/v1/stock/transfer', [
        'from_location_id' => test()->fromLoc->id,
        'to_location_id' => test()->toLoc->id,
        'ref_no' => 'ST-1',
        'transaction_date' => '2024-02-10T08:30:00',
        'shipping_charges' => 5.5,
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 8,
                'unit_price' => 2.5,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.type', 'sell_transfer');
    $response->assertJsonPath('data.location_id', test()->fromLoc->id);
    $response->assertJsonPath('data.business_id', test()->business->id);

    $afterFrom = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->fromLoc->id)
        ->value('qty_available');
    $afterTo = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->toLoc->id)
        ->value('qty_available');

    expect($afterFrom)->toEqual(22.0);   // 30 - 8
    expect($afterTo)->toEqual(8.0);     // 0 + 8 (newly inserted row)

    // Conservation: total stock across both locations equals what we
    // started with.
    expect($afterFrom + $afterTo)->toEqual($beforeFrom);
});

it('rejects a transfer when from_location_id equals to_location_id', function () {
    grantStockTransferPermissions();
    loginStockTransferUser();

    $response = $this->postJson('/api/v1/stock/transfer', [
        'from_location_id' => test()->fromLoc->id,
        'to_location_id' => test()->fromLoc->id, // same as `from`
        'transaction_date' => '2024-02-10T08:30:00',
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
        'errors' => ['to_location_id'],
    ]);

    // No transaction row was created — the validation failure
    // short-circuited before the controller body ran.
    expect(DB::table('transactions')->count())->toBe(0);
});

it('creates paired sell_transfer and purchase_transfer transactions linked by transfer_parent_id', function () {
    grantStockTransferPermissions();
    loginStockTransferUser();

    $response = $this->postJson('/api/v1/stock/transfer', [
        'from_location_id' => test()->fromLoc->id,
        'to_location_id' => test()->toLoc->id,
        'ref_no' => 'ST-PAIR',
        'transaction_date' => '2024-02-10T08:30:00',
        'products' => [
            [
                'product_id' => test()->product->id,
                'variation_id' => test()->variation->id,
                'quantity' => 4,
                'unit_price' => 3,
            ],
        ],
    ]);

    $response->assertStatus(201);
    $sellId = $response->json('data.id');

    $sell = DB::table('transactions')->where('id', $sellId)->first();
    expect($sell)->not->toBeNull();
    expect($sell->type)->toBe('sell_transfer');
    expect((int) $sell->location_id)->toBe(test()->fromLoc->id);

    $purchase = DB::table('transactions')
        ->where('type', 'purchase_transfer')
        ->where('transfer_parent_id', $sellId)
        ->first();
    expect($purchase)->not->toBeNull();
    expect((int) $purchase->location_id)->toBe(test()->toLoc->id);

    // Two purchase_lines rows exist — one per transaction — both with
    // matching product/variation/quantity values.
    $sellLines = DB::table('purchase_lines')->where('transaction_id', $sell->id)->get();
    $purchaseLines = DB::table('purchase_lines')->where('transaction_id', $purchase->id)->get();

    expect($sellLines)->toHaveCount(1);
    expect($purchaseLines)->toHaveCount(1);
    expect((float) $sellLines[0]->quantity)->toEqual(4.0);
    expect((float) $purchaseLines[0]->quantity)->toEqual(4.0);
    expect((int) $sellLines[0]->variation_id)->toBe(test()->variation->id);
    expect((int) $purchaseLines[0]->variation_id)->toBe(test()->variation->id);
});
