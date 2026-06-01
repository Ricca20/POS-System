<?php

use App\Business;
use App\BusinessLocation;
use App\Contact;
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
| `PUT /api/v1/pos/sales/{id}` (Pest, task 5.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated PUT updates a fresh sale → 200, totals recalculated,
|      stock adjusted by the diff (line quantity changed: stock moved by
|      the delta).
|   2. Adding a new line → stock decremented for the newly-added
|      variation; existing lines unaffected.
|   3. Removing an existing line → stock incremented back for the
|      removed variation.
|   4. Beyond edit window (transaction_date = 60 days ago,
|      transaction_edit_days = 30) → 403 with code='edit_window_expired'.
|   5. Without `sell.update` permission → 403.
|   6. PUT for a sale belonging to another business → 404 (no leak).
|
| Bootstrap mirrors `PosSaleCreateTest.php` and adds the
| `transaction_edit_days` column to the `business` table so the edit
| window check can be exercised.
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
        // The edit-window check reads `transaction_edit_days` from the
        // business row; default of 30 days matches the production
        // default.
        $t->integer('transaction_edit_days')->unsigned()->default(30);
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

    Schema::create('contacts', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('type')->default('customer');
        $t->boolean('is_default')->default(false);
        $t->string('name')->nullable();
        $t->string('supplier_business_name')->nullable();
        $t->string('contact_status')->default('active');
        $t->softDeletes();
        $t->timestamps();
    });

    Schema::create('tax_rates', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->decimal('amount', 8, 4)->default(0);
        $t->boolean('is_tax_group')->default(false);
        $t->boolean('for_tax_group')->default(false);
        $t->integer('business_id')->unsigned();
        $t->softDeletes();
        $t->timestamps();
    });

    Schema::create('transactions', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->integer('location_id')->unsigned();
        $t->string('type')->default('sell');
        $t->string('sub_type')->nullable();
        $t->string('status')->default('final');
        $t->string('payment_status')->nullable();
        $t->dateTime('transaction_date');
        $t->integer('contact_id')->unsigned()->nullable();
        $t->string('invoice_no')->nullable();
        $t->decimal('total_before_tax', 22, 4)->default(0);
        $t->decimal('tax_amount', 22, 4)->default(0);
        $t->integer('tax_id')->unsigned()->nullable();
        $t->string('discount_type')->nullable();
        $t->decimal('discount_amount', 22, 4)->default(0);
        $t->decimal('shipping_charges', 22, 4)->default(0);
        $t->decimal('final_total', 22, 4)->default(0);
        $t->boolean('is_direct_sale')->default(false);
        $t->text('additional_notes')->nullable();
        $t->integer('created_by')->unsigned();
        $t->timestamps();
    });

    Schema::create('transaction_sell_lines', function ($t) {
        $t->increments('id');
        $t->integer('transaction_id')->unsigned();
        $t->integer('product_id')->unsigned();
        $t->integer('variation_id')->unsigned();
        $t->decimal('quantity', 22, 4);
        $t->decimal('unit_price', 22, 4)->default(0);
        $t->decimal('unit_price_inc_tax', 22, 4)->default(0);
        $t->integer('tax_id')->unsigned()->nullable();
        $t->decimal('item_tax', 22, 4)->default(0);
        $t->string('line_discount_type')->nullable();
        $t->decimal('line_discount_amount', 22, 4)->default(0);
        $t->softDeletes();
        $t->timestamps();
    });

    Schema::create('transaction_payments', function ($t) {
        $t->increments('id');
        $t->integer('transaction_id')->unsigned();
        $t->integer('business_id')->unsigned();
        $t->boolean('is_return')->default(false);
        $t->decimal('amount', 22, 4);
        $t->string('method', 32);
        $t->integer('payment_for')->unsigned()->nullable();
        $t->dateTime('paid_on');
        $t->integer('created_by')->unsigned();
        $t->string('payment_ref_no')->nullable();
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
        'transaction_edit_days' => 30,
    ]);

    $otherBusiness = Business::create([
        'name' => 'Rival POS',
        'currency_id' => $currencyId,
        'time_zone' => 'UTC',
        'is_active' => true,
        'transaction_edit_days' => 30,
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

    $aliceLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
    ]);

    $rivalLoc = BusinessLocation::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Outlet',
    ]);

    $product1 = Product::create([
        'business_id' => $business->id,
        'name' => 'Widget',
        'type' => 'single',
        'sku' => 'W-1',
        'unit_id' => 1,
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => $user->id,
    ]);
    $variation1 = Variation::create([
        'name' => 'DUMMY',
        'product_id' => $product1->id,
        'sub_sku' => 'W-1-V',
        'default_sell_price' => 10.0,
        'default_sell_price_inc_tax' => 10.0,
    ]);
    VariationLocationDetails::create([
        'product_id' => $product1->id,
        'variation_id' => $variation1->id,
        'location_id' => $aliceLoc->id,
        'qty_available' => 100,
    ]);

    $product2 = Product::create([
        'business_id' => $business->id,
        'name' => 'Gadget',
        'type' => 'single',
        'sku' => 'G-1',
        'unit_id' => 1,
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => $user->id,
    ]);
    $variation2 = Variation::create([
        'name' => 'DUMMY',
        'product_id' => $product2->id,
        'sub_sku' => 'G-1-V',
        'default_sell_price' => 25.0,
        'default_sell_price_inc_tax' => 25.0,
    ]);
    VariationLocationDetails::create([
        'product_id' => $product2->id,
        'variation_id' => $variation2->id,
        'location_id' => $aliceLoc->id,
        'qty_available' => 50,
    ]);

    $walkIn = Contact::create([
        'business_id' => $business->id,
        'type' => 'customer',
        'is_default' => true,
        'name' => 'Walk In Customer',
        'contact_status' => 'active',
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceLoc = $aliceLoc;
    test()->rivalLoc = $rivalLoc;
    test()->product1 = $product1;
    test()->variation1 = $variation1;
    test()->product2 = $product2;
    test()->variation2 = $variation2;
    test()->walkIn = $walkIn;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginPosSaleEditUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant the permissions exercised by the edit endpoint via Gate::define.
 * Tests that need the permission absent simply skip this helper.
 */
function grantSellEditPerms(): void
{
    Gate::define('sell.create', fn ($user) => true);
    Gate::define('sell.update', fn ($user) => true);
}

/**
 * Helper to seed a fresh sale via the create endpoint so the edit
 * test can operate on a known-good baseline. Returns the sale id.
 */
function createBaselineSale(array $overrides = []): int
{
    $payload = array_merge([
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => now()->toDateTimeString(),
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 30, 'method' => 'cash'],
        ],
    ], $overrides);

    $response = test()->postJson('/api/v1/pos/sales', $payload);
    $response->assertStatus(201);

    return (int) $response->json('data.id');
}

it('updates an existing sale and adjusts stock by the line quantity delta', function () {
    grantSellEditPerms();
    loginPosSaleEditUser();

    $saleId = createBaselineSale();

    // After create: stock = 100 - 3 = 97.
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(97.0);

    // PUT bumps quantity from 3 → 5: stock should drop by 2 more (97 - 2 = 95).
    $response = $this->putJson("/api/v1/pos/sales/{$saleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 50, 'method' => 'cash'],
        ],
    ]);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    $response->assertJsonPath('data.id', $saleId);
    $response->assertJsonPath('data.payment_status', 'paid');
    expect($response->json('data.final_total'))->toEqual(50.0);
    expect($response->json('data.total_before_tax'))->toEqual(50.0);

    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(95.0);

    // sell_line row reflects the new quantity.
    $line = DB::table('transaction_sell_lines')
        ->where('transaction_id', $saleId)
        ->where('variation_id', test()->variation1->id)
        ->first();
    expect((float) $line->quantity)->toEqual(5.0);

    // payments were replaced (still 1 row, but with the new amount).
    $payments = DB::table('transaction_payments')->where('transaction_id', $saleId)->get();
    expect($payments)->toHaveCount(1);
    expect((float) $payments[0]->amount)->toEqual(50.0);
});

it('decrements stock for a newly added line on edit', function () {
    grantSellEditPerms();
    loginPosSaleEditUser();

    $saleId = createBaselineSale();

    // Variation 2 stock is 50 before the edit; adding a 4-unit line
    // should drop it to 46.
    $startQty2 = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation2->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($startQty2)->toEqual(50.0);

    $response = $this->putJson("/api/v1/pos/sales/{$saleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
            ],
            [
                'product_id' => test()->product2->id,
                'variation_id' => test()->variation2->id,
                'quantity' => 4,
                'unit_price' => 25,
            ],
        ],
    ]);

    $response->assertStatus(200);
    // total_before_tax = 3*10 + 4*25 = 130.
    expect($response->json('data.total_before_tax'))->toEqual(130.0);

    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation2->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(46.0);

    // Variation 1 stock unchanged from baseline (97).
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(97.0);

    expect(DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->count())->toBe(2);
});

it('increments stock when a line is removed on edit', function () {
    grantSellEditPerms();
    loginPosSaleEditUser();

    // Seed a 2-line sale so we can drop one cleanly.
    $saleId = createBaselineSale([
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
            ],
            [
                'product_id' => test()->product2->id,
                'variation_id' => test()->variation2->id,
                'quantity' => 2,
                'unit_price' => 25,
            ],
        ],
        'payments' => [
            ['amount' => 80, 'method' => 'cash'],
        ],
    ]);

    // After baseline create: var1 100-3=97, var2 50-2=48.
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation2->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(48.0);

    // Drop variation 2 entirely.
    $response = $this->putJson("/api/v1/pos/sales/{$saleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 30, 'method' => 'cash'],
        ],
    ]);

    $response->assertStatus(200);
    expect($response->json('data.total_before_tax'))->toEqual(30.0);

    // Variation 2 stock returned: 48 + 2 = 50.
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation2->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(50.0);

    // Variation 1 stock unchanged.
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(97.0);

    // Only one sell_line remains.
    expect(DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->whereNull('deleted_at')->count())->toBe(1);
});

it('returns 403 with edit_window_expired when the sale is older than transaction_edit_days', function () {
    grantSellEditPerms();
    loginPosSaleEditUser();

    // Seed a sale dated 60 days ago; business default is 30 days.
    $saleId = createBaselineSale([
        'transaction_date' => now()->subDays(60)->toDateTimeString(),
    ]);

    $response = $this->putJson("/api/v1/pos/sales/{$saleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ],
        ],
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'edit_window_expired');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // No mutations: stock untouched (still 97 from baseline create).
    expect((float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available'))->toEqual(97.0);
});

it('returns 403 when the user lacks the sell.update permission', function () {
    // Grant only sell.create so we can seed a sale, then test edit
    // without sell.update.
    Gate::define('sell.create', fn ($user) => true);
    loginPosSaleEditUser();

    $saleId = createBaselineSale();

    // No `sell.update` granted: PUT must 403.
    $response = $this->putJson("/api/v1/pos/sales/{$saleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ],
        ],
    ]);

    $response->assertStatus(403);

    // The persisted sale was not modified.
    $row = DB::table('transactions')->where('id', $saleId)->first();
    expect((float) $row->final_total)->toEqual(30.0);
});

it('returns 404 for a sale that belongs to another business', function () {
    grantSellEditPerms();
    loginPosSaleEditUser();

    // Insert a foreign-business sale directly so we don't need to
    // authenticate as a different user.
    $foreignSaleId = DB::table('transactions')->insertGetId([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'type' => 'sell',
        'sub_type' => 'pos',
        'status' => 'final',
        'payment_status' => 'paid',
        'transaction_date' => '2024-03-10 09:00:00',
        'contact_id' => null,
        'invoice_no' => 'INV-RIVAL-1',
        'total_before_tax' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 100,
        'is_direct_sale' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->putJson("/api/v1/pos/sales/{$foreignSaleId}", [
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ],
        ],
    ]);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});
