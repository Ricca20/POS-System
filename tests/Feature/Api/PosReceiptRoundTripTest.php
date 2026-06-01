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
| `GET /api/v1/pos/sales/{id}/receipt` (Pest, task 5.4)
|--------------------------------------------------------------------------
|
| Validates: R13.2, R13.3, R13.6.
|
| Asserts:
|   1. POST a single-line single-payment sale, then GET the receipt;
|      the rendered HTML contains the line item name, sub_sku, quantity,
|      unit_price, total, payment method+amount, grand total, and
|      invoice_no.
|   2. Multi-line sale -> every line item appears in the HTML.
|   3. Multi-payment sale -> every payment line appears in the HTML.
|   4. GET receipt for a non-existent id -> 404 envelope.
|   5. GET receipt for a sale belonging to another business -> 404
|      envelope (avoids existence leaks).
|   6. Without `sell.view` permission -> 403 envelope.
|   7. R13.6 strict check: response Content-Type is `application/json`
|      (NOT text/html). The Blade is rendered into a JSON-encapsulated
|      `html` string; the response itself is JSON.
|
| Bootstrap mirrors `PosSaleCreateTest.php` because we POST a real sale
| and then GET its receipt — exercising the same persistence path
| through `transactions`, `transaction_sell_lines`,
| `transaction_payments`. We additionally seed `mobile`, `email`, and
| address columns on the location so the rendered receipt has the full
| header to assert against.
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
        $t->string('currency_symbol_placement')->default('before');
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
        // Address + contact columns the receipt template renders.
        $t->string('landmark')->nullable();
        $t->string('city')->nullable();
        $t->string('state')->nullable();
        $t->string('zip_code')->nullable();
        $t->string('country')->nullable();
        $t->string('mobile')->nullable();
        $t->string('email')->nullable();
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
        $t->string('mobile')->nullable();
        $t->string('email')->nullable();
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

    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    $aliceLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
        'landmark' => '12 Main St',
        'city' => 'Springfield',
        'state' => 'IL',
        'zip_code' => '62701',
        'country' => 'USA',
        'mobile' => '555-0100',
        'email' => 'shop@acme.test',
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
        'sub_sku' => 'WIDGET-SKU-001',
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
        'sub_sku' => 'GADGET-SKU-002',
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
        'name' => 'Jane Roe',
        'mobile' => '555-9999',
        'email' => 'jane@example.test',
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

function loginPosReceiptUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant both `sell.create` (needed to POST the sale) and `sell.view`
 * (needed to GET the receipt). Spatie's Gate::before returns null
 * when the permission row is missing, so Laravel falls through to the
 * defined gate.
 */
function grantSellReceiptPerms(): void
{
    Gate::define('sell.create', fn ($user) => true);
    Gate::define('sell.view', fn ($user) => true);
}

/**
 * Grant only `sell.create`. Used by the 403 test so the POST succeeds
 * but the receipt GET is rejected.
 */
function grantSellCreateForReceipt(): void
{
    Gate::define('sell.create', fn ($user) => true);
}

/**
 * POST a single-line single-payment sale and return its id. Centralized
 * so each test does not redeclare the same body.
 */
function postReceiptSale(array $overrides = []): int
{
    $body = array_replace_recursive([
        'location_id' => test()->aliceLoc->id,
        'transaction_date' => '2024-03-10T09:00:00',
        'contact_id' => test()->walkIn->id,
        'invoice_no' => 'INV-RCPT-001',
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 2,
                'unit_price' => 10,
                'unit_price_inc_tax' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 20, 'method' => 'cash'],
        ],
    ], $overrides);

    $response = test()->postJson('/api/v1/pos/sales', $body);
    $response->assertStatus(201);

    return (int) $response->json('data.id');
}

it('returns receipt HTML containing every line, total, and payment for a single-line sale', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    $saleId = postReceiptSale();

    $response = $this->getJson("/api/v1/pos/sales/{$saleId}/receipt");

    // R13.6: response itself is JSON; the Blade is encapsulated inside.
    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => ['sale_id', 'html'],
    ]);
    expect($response->json('data.sale_id'))->toBe($saleId);

    $html = $response->json('data.html');
    expect($html)->toBeString();
    // The rendered HTML is the Blade template's output.
    expect($html)->toStartWith('<!DOCTYPE html>');

    // Round-trip fidelity: persistence -> render must contain every
    // field the SPA needs on the receipt.
    expect($html)->toContain('INV-RCPT-001');               // invoice_no
    expect($html)->toContain('Acme POS');                   // business name
    expect($html)->toContain('Acme HQ');                    // location name
    expect($html)->toContain('Widget');                     // product name
    expect($html)->toContain('WIDGET-SKU-001');             // sub_sku
    expect($html)->toContain('Jane Roe');                   // customer name
    expect($html)->toContain('cash');                       // payment method
    expect($html)->toContain('$20.00');                     // payment amount
    expect($html)->toContain('$10.00');                     // unit price
    expect($html)->toContain('paid');                       // payment status
});

it('renders every line item from a multi-line sale', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    $saleId = postReceiptSale([
        'invoice_no' => 'INV-MULTI-LINES',
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 3,
                'unit_price' => 10,
                'unit_price_inc_tax' => 10,
            ],
            [
                'product_id' => test()->product2->id,
                'variation_id' => test()->variation2->id,
                'quantity' => 2,
                'unit_price' => 25,
                'unit_price_inc_tax' => 25,
            ],
        ],
        'payments' => [
            ['amount' => 80, 'method' => 'cash'], // 3*10 + 2*25 = 80
        ],
    ]);

    $response = $this->getJson("/api/v1/pos/sales/{$saleId}/receipt");
    $response->assertStatus(200);

    $html = $response->json('data.html');

    // Every line must appear with its name + sku + total.
    expect($html)->toContain('Widget');
    expect($html)->toContain('WIDGET-SKU-001');
    expect($html)->toContain('Gadget');
    expect($html)->toContain('GADGET-SKU-002');

    // Per-line totals: 3 * $10 = $30 and 2 * $25 = $50.
    expect($html)->toContain('$30.00');
    expect($html)->toContain('$50.00');

    // Grand total: $80.00 must be present.
    expect($html)->toContain('$80.00');
});

it('renders every payment line from a split-payment sale', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    $saleId = postReceiptSale([
        'invoice_no' => 'INV-SPLIT-PAY',
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 5,
                'unit_price' => 10,
                'unit_price_inc_tax' => 10,
            ],
        ],
        'payments' => [
            ['amount' => 20, 'method' => 'cash'],
            ['amount' => 30, 'method' => 'card'],
        ],
    ]);

    $response = $this->getJson("/api/v1/pos/sales/{$saleId}/receipt");
    $response->assertStatus(200);

    $html = $response->json('data.html');

    // Both payment methods + their amounts must appear.
    expect($html)->toContain('cash');
    expect($html)->toContain('$20.00');
    expect($html)->toContain('card');
    expect($html)->toContain('$30.00');

    // Grand total: 5 * $10 = $50.
    expect($html)->toContain('$50.00');
});

it('returns 404 envelope for a non-existent sale id', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    $response = $this->getJson('/api/v1/pos/sales/99999/receipt');

    $response->assertStatus(404);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    $response->assertJsonPath('code', 'not_found');
});

it('returns 404 envelope for a sale belonging to another business', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    // Insert a sell directly for the other business — it must never
    // be reachable from Alice's session.
    $foreignSaleId = (int) DB::table('transactions')->insertGetId([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'type' => 'sell',
        'sub_type' => 'pos',
        'status' => 'final',
        'payment_status' => 'paid',
        'transaction_date' => '2024-03-10 09:00:00',
        'contact_id' => null,
        'invoice_no' => 'INV-FOREIGN',
        'total_before_tax' => 10,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 10,
        'is_direct_sale' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/pos/sales/{$foreignSaleId}/receipt");

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('returns 403 envelope when the user lacks the sell.view permission', function () {
    // Grant only `sell.create` so we can POST the sale; do NOT grant
    // `sell.view` so the GET hits the permission gate.
    grantSellCreateForReceipt();
    loginPosReceiptUser();

    $saleId = postReceiptSale(['invoice_no' => 'INV-NO-VIEW']);

    $response = $this->getJson("/api/v1/pos/sales/{$saleId}/receipt");

    $response->assertStatus(403);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    $response->assertJsonPath('code', 'forbidden');
});

it('returns application/json (not text/html) so the rendered Blade is contained in the JSON envelope', function () {
    grantSellReceiptPerms();
    loginPosReceiptUser();

    $saleId = postReceiptSale(['invoice_no' => 'INV-CT-CHECK']);

    $response = $this->getJson("/api/v1/pos/sales/{$saleId}/receipt");
    $response->assertStatus(200);

    // R13.6: the response itself must be JSON. The Blade is rendered
    // *inside* the `data.html` string, never returned as the body
    // directly. This is the contract that lets the Electron print
    // pipeline forward the HTML without parsing the response twice.
    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->toStartWith('application/json');
    expect($contentType)->not->toContain('text/html');

    // The body parses as JSON.
    $payload = $response->json();
    expect($payload)->toBeArray();
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toHaveKey('html');
    expect($payload['data'])->toHaveKey('sale_id');

    // And the HTML is the Blade output (not the response body).
    expect($payload['data']['html'])->toContain('<!DOCTYPE html>');
    expect($payload['data']['html'])->toContain('INV-CT-CHECK');
});
