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
| `POST /api/v1/pos/sales` (Pest, task 5.2)
|--------------------------------------------------------------------------
|
| Validates: R13.1.
|
| Asserts:
|   1. Authenticated POST with valid body (single product, single
|      payment fully paid) -> 201 with `data.id`, `payment_status='paid'`,
|      sell_lines + payments persisted, stock decremented.
|   2. Multiple products -> 201, each line persisted, each stock row
|      decremented by exactly the line quantity.
|   3. Partial payment -> 201 with `payment_status='partial'`.
|   4. Sale with no `payments` key -> 201 with `payment_status='due'`.
|   5. Without `sell.create` permission -> 403.
|   6. Invalid body (no `products` array) -> 422 with `errors.products`.
|   7. Cross-business `location_id` -> 422 with `errors.location_id`.
|   8. Stock decrement is atomic — verify qty_available decremented by
|      exactly the line quantity.
|
| Bootstrap mirrors `PosConfigTest.php` (currencies, business, users,
| personal_access_tokens, business_locations, products, variations,
| variation_location_details, contacts, spatie permission tables) and
| extends the schema with `transactions`, `transaction_sell_lines`,
| `transaction_payments` so the create flow can be exercised end-to-end.
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

    // Spatie permission tables — kept empty; the Gate::define override
    // for `sell.create` short-circuits the spatie lookup.
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

    // The create flow writes to `transactions`, `transaction_sell_lines`,
    // `transaction_payments`. Schema is the trim subset of legacy columns
    // touched by the controller.
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

    // Eloquent caches column listings statically per model class; clear
    // via reflection so this test's fresh schema is honoured.
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

    // Seed two products + variations + stock rows so the multi-line
    // tests can verify per-line decrements.
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

    // Walk-in customer for Alice's business; the controller requires
    // a contact_id scoped to the auth business.
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

/**
 * Authenticate the seeded user via the live login endpoint so the
 * Sanctum stateful cookie flow + `SetSessionDataApi` middleware are
 * exercised end-to-end.
 */
function loginPosSaleUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant `sell.create` via Gate::define. Spatie's Gate::before returns
 * null when the permission row is missing, so Laravel falls through to
 * this defined gate.
 */
function grantSellCreateForSale(): void
{
    Gate::define('sell.create', fn ($user) => true);
}

it('creates a fully-paid sale and decrements stock atomically', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $startQty = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($startQty)->toEqual(100.0);

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'invoice_no' => 'INV-TEST-1',
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            [
                'amount' => 30,
                'method' => 'cash',
            ],
        ],
    ]);

    $response->assertStatus(201);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'id',
            'business_id',
            'location_id',
            'contact_id',
            'invoice_no',
            'transaction_date',
            'total_before_tax',
            'tax_amount',
            'discount_amount',
            'shipping_charges',
            'final_total',
            'payment_status',
            'created_by',
            'created_at',
            'sell_lines',
            'payments',
        ],
    ]);

    $response->assertJsonPath('data.payment_status', 'paid');
    $response->assertJsonPath('data.invoice_no', 'INV-TEST-1');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.location_id', test()->aliceLoc->id);
    $response->assertJsonPath('data.contact_id', test()->walkIn->id);
    $response->assertJsonPath('data.created_by', test()->user->id);

    expect($response->json('data.final_total'))->toEqual(30.0);
    expect($response->json('data.total_before_tax'))->toEqual(30.0);

    $saleId = (int) $response->json('data.id');

    // Persisted transactions row.
    $row = DB::table('transactions')->where('id', $saleId)->first();
    expect($row)->not->toBeNull();
    expect($row->type)->toBe('sell');
    expect($row->sub_type)->toBe('pos');
    expect($row->status)->toBe('final');
    expect($row->payment_status)->toBe('paid');

    // sell_line and payment rows persisted.
    $lines = DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->get();
    expect($lines)->toHaveCount(1);
    expect((int) $lines[0]->variation_id)->toBe(test()->variation1->id);
    expect((float) $lines[0]->quantity)->toEqual(3.0);

    $payments = DB::table('transaction_payments')->where('transaction_id', $saleId)->get();
    expect($payments)->toHaveCount(1);
    expect($payments[0]->method)->toBe('cash');
    expect((float) $payments[0]->amount)->toEqual(30.0);

    // Stock decremented by exactly the line quantity.
    $endQty = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($endQty)->toEqual(97.0);
    expect($startQty - $endQty)->toEqual(3.0);
});

it('creates a sale with multiple products and decrements each stock row by its line quantity', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 4,
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
            ['amount' => 90, 'method' => 'cash'],
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.payment_status', 'paid');
    expect($response->json('data.final_total'))->toEqual(90.0); // 4*10 + 2*25 = 90

    $saleId = (int) $response->json('data.id');
    $lines = DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->get();
    expect($lines)->toHaveCount(2);

    // Variation 1 stock: 100 - 4 = 96.
    $qty1 = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($qty1)->toEqual(96.0);

    // Variation 2 stock: 50 - 2 = 48.
    $qty2 = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation2->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($qty2)->toEqual(48.0);
});

it('records a partial payment_status when payment sum is less than the grand total', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 20, 'method' => 'cash'], // 20 < 50 grand total
        ],
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.payment_status', 'partial');
    expect($response->json('data.final_total'))->toEqual(50.0);
});

it('records a due payment_status when no payments are provided', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 1,
                'unit_price' => 10,
            ],
        ],
        // no `payments` key at all
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.payment_status', 'due');
    expect($response->json('data.payments'))->toBe([]);

    // Same outcome with an explicit empty payments array.
    $response2 = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 1,
                'unit_price' => 10,
            ],
        ],
        'payments' => [],
    ]);

    $response2->assertStatus(201);
    $response2->assertJsonPath('data.payment_status', 'due');
});

it('returns 403 when the user lacks the sell.create permission', function () {
    // No `grantSellCreateForSale()` -> no permission.
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 1,
                'unit_price' => 10,
            ],
        ],
    ]);

    $response->assertStatus(403);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // No row was created.
    expect(DB::table('transactions')->count())->toBe(0);
});

it('returns 422 when the products array is missing', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        // no `products` key
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['products'],
    ]);

    expect(DB::table('transactions')->count())->toBe(0);
});

it('rejects a sale posted against a location in a different business', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->rivalLoc->id, // cross-business
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 1,
                'unit_price' => 10,
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

    expect(DB::table('transactions')->count())->toBe(0);
});

it('decrements stock by exactly the line quantity (delta check)', function () {
    grantSellCreateForSale();
    loginPosSaleUser();

    $before = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->postJson('/api/v1/pos/sales', [
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 7.5,
                'unit_price' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 75, 'method' => 'card'],
        ],
    ]);

    $response->assertStatus(201);

    $after = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    expect($before - $after)->toEqual(7.5);
});
