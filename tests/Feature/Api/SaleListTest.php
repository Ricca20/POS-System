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
| `SaleApiController` (Pest, task 5.5)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   a) GET /api/v1/sales without `sell.view` permission -> 403.
|   b) GET /api/v1/sales (with permission) -> 200 paginated, only
|      own-business final sales (cross-business sales excluded).
|   c) GET /api/v1/sales/{id} with permission -> full PosSaleResource
|      shape including `sell_lines` and `payments` keys.
|   d) GET /api/v1/sales/{id} for a sale belonging to another
|      business -> 404.
|   e) GET /api/v1/sales/drafts returns rows where `status='draft'`
|      AND (is_quotation=0 OR is_quotation IS NULL).
|   f) GET /api/v1/sales/quotations returns rows where `status='draft'`
|      AND `is_quotation=1`.
|   g) POST /api/v1/sales/{id}/duplicate clones lines without
|      copying payments and without decrementing stock.
|   h) DELETE /api/v1/sales/{id} (recent final) -> 204; row + lines
|      + payments deleted; stock incremented back.
|   i) DELETE /api/v1/sales/{id} for a sale older than
|      `transaction_edit_days` -> 403 with code='edit_window_expired'.
|
| Bootstrap mirrors `PosSaleCreateTest.php` (full SQLite schema:
| currencies, business, users, personal_access_tokens, spatie permission
| tables, business_locations, products, variations,
| variation_location_details, contacts, tax_rates, transactions,
| transaction_sell_lines, transaction_payments) with two additions to
| the `transactions` schema:
|   - `is_quotation` (boolean, default false)
|   - `return_parent_id` (unsigned int, nullable)
|
| Permissions are granted per test via Gate::define.
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
        // The destroy edit-window check reads `transaction_edit_days`
        // from the business row.
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

    // The sales-management flow reads `is_quotation` (drafts vs
    // quotations) and writes `return_parent_id` (when a sell-return is
    // created). Both columns are added on top of the baseline transactions
    // schema used by the sister tests.
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
        $t->boolean('is_quotation')->default(false);
        $t->integer('return_parent_id')->unsigned()->nullable();
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
    test()->walkIn = $walkIn;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginSaleListUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantSellViewForList(): void
{
    Gate::define('sell.view', fn ($user) => true);
}

function grantSellCreateForList(): void
{
    Gate::define('sell.create', fn ($user) => true);
}

function grantSellDeleteForList(): void
{
    Gate::define('sell.delete', fn ($user) => true);
}

/**
 * Insert a `transactions` row with sensible defaults. Returns the new
 * id. Override any column via the supplied array.
 */
function seedSale(array $overrides = []): int
{
    $defaults = [
        'business_id' => test()->business->id,
        'location_id' => test()->aliceLoc->id,
        'type' => 'sell',
        'sub_type' => 'pos',
        'status' => 'final',
        'payment_status' => 'paid',
        'transaction_date' => '2024-03-10 09:00:00',
        'contact_id' => test()->walkIn->id,
        'invoice_no' => 'INV-SEED',
        'total_before_tax' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 100,
        'is_direct_sale' => 0,
        'is_quotation' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return (int) DB::table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

/**
 * Insert a sell_line row tied to a transaction id. Returns the row id.
 */
function seedSellLine(int $transactionId, array $overrides = []): int
{
    $defaults = [
        'transaction_id' => $transactionId,
        'product_id' => test()->product1->id,
        'variation_id' => test()->variation1->id,
        'quantity' => 1,
        'unit_price' => 10,
        'unit_price_inc_tax' => 10,
        'item_tax' => 0,
        'line_discount_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return (int) DB::table('transaction_sell_lines')->insertGetId(array_merge($defaults, $overrides));
}

/**
 * Insert a payment row tied to a transaction id. Returns the row id.
 */
function seedPayment(int $transactionId, array $overrides = []): int
{
    $defaults = [
        'transaction_id' => $transactionId,
        'business_id' => test()->business->id,
        'is_return' => 0,
        'amount' => 10,
        'method' => 'cash',
        'paid_on' => '2024-03-10 09:00:00',
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return (int) DB::table('transaction_payments')->insertGetId(array_merge($defaults, $overrides));
}

it('returns 403 when GET /sales is called without sell.view permission', function () {
    loginSaleListUser();

    $response = $this->getJson('/api/v1/sales');
    $response->assertStatus(403);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('lists only own-business final sales paginated', function () {
    grantSellViewForList();
    loginSaleListUser();

    seedSale(['invoice_no' => 'INV-A', 'transaction_date' => '2024-03-10 09:00:00']);
    seedSale(['invoice_no' => 'INV-B', 'transaction_date' => '2024-04-01 09:00:00']);
    seedSale(['invoice_no' => 'INV-C', 'transaction_date' => '2024-04-15 12:00:00']);

    // Foreign-business sale must NOT appear.
    seedSale([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'invoice_no' => 'INV-RIVAL',
    ]);

    $response = $this->getJson('/api/v1/sales');
    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $rows = $response->json('data');
    expect($rows)->toBeArray();
    expect($rows)->toHaveCount(3);

    // Ordered by transaction_date DESC.
    expect($rows[0]['invoice_no'])->toBe('INV-C');
    expect($rows[1]['invoice_no'])->toBe('INV-B');
    expect($rows[2]['invoice_no'])->toBe('INV-A');

    // Every row scoped to the auth business.
    foreach ($rows as $row) {
        expect((int) $row['business_id'])->toBe(test()->business->id);
    }

    // Resource collection emits pagination meta.
    expect($response->json('meta.total'))->toBe(3);
});

it('returns the full PosSaleResource shape with sell_lines and payments on show', function () {
    grantSellViewForList();
    loginSaleListUser();

    $saleId = seedSale(['invoice_no' => 'INV-DETAIL']);
    seedSellLine($saleId, ['quantity' => 3, 'unit_price' => 10, 'unit_price_inc_tax' => 10]);
    seedPayment($saleId, ['amount' => 30, 'method' => 'cash']);

    $response = $this->getJson("/api/v1/sales/{$saleId}");
    $response->assertStatus(200);
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

    expect($response->json('data.sell_lines'))->toHaveCount(1);
    expect($response->json('data.payments'))->toHaveCount(1);
    expect((float) $response->json('data.sell_lines.0.quantity'))->toEqual(3.0);
    expect((float) $response->json('data.payments.0.amount'))->toEqual(30.0);
});

it('returns 404 when GET /sales/{id} targets a sale in another business', function () {
    grantSellViewForList();
    loginSaleListUser();

    $foreignId = seedSale([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'invoice_no' => 'INV-RIVAL',
    ]);

    $response = $this->getJson("/api/v1/sales/{$foreignId}");
    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('returns only non-quotation drafts on GET /sales/drafts', function () {
    grantSellViewForList();
    loginSaleListUser();

    // Mix of statuses + quotation flag.
    $finalId = seedSale(['status' => 'final', 'invoice_no' => 'INV-FINAL']);
    $draftId = seedSale(['status' => 'draft', 'is_quotation' => 0, 'invoice_no' => 'INV-DRAFT']);
    $quotationId = seedSale(['status' => 'draft', 'is_quotation' => 1, 'invoice_no' => 'INV-QUOTE']);

    $response = $this->getJson('/api/v1/sales/drafts');
    $response->assertStatus(200);

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['id'])->toBe($draftId);
    expect($rows[0]['invoice_no'])->toBe('INV-DRAFT');

    // Final and quotation rows are excluded.
    $invoiceNos = collect($rows)->pluck('invoice_no')->all();
    expect($invoiceNos)->not->toContain('INV-FINAL');
    expect($invoiceNos)->not->toContain('INV-QUOTE');
});

it('returns only quotations on GET /sales/quotations', function () {
    grantSellViewForList();
    loginSaleListUser();

    $finalId = seedSale(['status' => 'final', 'invoice_no' => 'INV-FINAL']);
    $draftId = seedSale(['status' => 'draft', 'is_quotation' => 0, 'invoice_no' => 'INV-DRAFT']);
    $quotationId = seedSale(['status' => 'draft', 'is_quotation' => 1, 'invoice_no' => 'INV-QUOTE']);

    $response = $this->getJson('/api/v1/sales/quotations');
    $response->assertStatus(200);

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['id'])->toBe($quotationId);
    expect($rows[0]['invoice_no'])->toBe('INV-QUOTE');
});

it('clones lines into a new draft on POST /sales/{id}/duplicate without copying payments or decrementing stock', function () {
    grantSellCreateForList();
    loginSaleListUser();

    $saleId = seedSale([
        'invoice_no' => 'INV-ORIGINAL',
        'final_total' => 50,
        'total_before_tax' => 50,
        'payment_status' => 'paid',
    ]);
    seedSellLine($saleId, ['quantity' => 2, 'unit_price' => 10, 'unit_price_inc_tax' => 10]);
    seedSellLine($saleId, ['quantity' => 3, 'unit_price' => 10, 'unit_price_inc_tax' => 10]);
    seedPayment($saleId, ['amount' => 50, 'method' => 'cash']);

    $stockBefore = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->postJson("/api/v1/sales/{$saleId}/duplicate");
    $response->assertStatus(201);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $newId = (int) $response->json('data.id');
    expect($newId)->not->toBe($saleId);

    // The new row is a draft, not a quotation.
    $newRow = DB::table('transactions')->where('id', $newId)->first();
    expect($newRow->status)->toBe('draft');
    expect((int) $newRow->is_quotation)->toBe(0);
    // Fresh invoice_no — not a copy of the source.
    expect($newRow->invoice_no)->not->toBe('INV-ORIGINAL');
    expect($newRow->invoice_no)->toStartWith('INV-');
    expect((int) $newRow->created_by)->toBe(test()->user->id);

    // Equal sell_lines count, no payments copied.
    expect(DB::table('transaction_sell_lines')->where('transaction_id', $newId)->count())->toBe(2);
    expect(DB::table('transaction_payments')->where('transaction_id', $newId)->count())->toBe(0);

    // Stock was NOT decremented.
    $stockAfter = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($stockAfter)->toEqual($stockBefore);

    // Original sale untouched.
    $original = DB::table('transactions')->where('id', $saleId)->first();
    expect($original->invoice_no)->toBe('INV-ORIGINAL');
    expect($original->status)->toBe('final');
});

it('deletes a recent final sale and increments stock back on DELETE /sales/{id}', function () {
    grantSellDeleteForList();
    loginSaleListUser();

    $saleId = seedSale([
        'invoice_no' => 'INV-DEL',
        'transaction_date' => now()->subDay()->toDateTimeString(),
    ]);
    seedSellLine($saleId, [
        'product_id' => test()->product1->id,
        'variation_id' => test()->variation1->id,
        'quantity' => 4,
        'unit_price' => 10,
    ]);
    seedPayment($saleId, ['amount' => 40, 'method' => 'cash']);

    $stockBefore = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->deleteJson("/api/v1/sales/{$saleId}");
    $response->assertStatus(204);

    // The transaction + lines + payments are gone.
    expect(DB::table('transactions')->where('id', $saleId)->count())->toBe(0);
    expect(DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->count())->toBe(0);
    expect(DB::table('transaction_payments')->where('transaction_id', $saleId)->count())->toBe(0);

    // Stock was incremented back by the line quantity.
    $stockAfter = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($stockAfter - $stockBefore)->toEqual(4.0);
});

it('rejects DELETE /sales/{id} when the sale is older than transaction_edit_days', function () {
    grantSellDeleteForList();
    loginSaleListUser();

    // 60 days ago, business default 30: outside the edit window.
    $saleId = seedSale([
        'invoice_no' => 'INV-OLD',
        'transaction_date' => now()->subDays(60)->toDateTimeString(),
    ]);
    seedSellLine($saleId, ['quantity' => 1, 'unit_price' => 10]);

    $stockBefore = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->deleteJson("/api/v1/sales/{$saleId}");
    $response->assertStatus(403);
    $response->assertJsonPath('code', 'edit_window_expired');

    // No mutations: row + line still present, stock unchanged.
    expect(DB::table('transactions')->where('id', $saleId)->count())->toBe(1);
    expect(DB::table('transaction_sell_lines')->where('transaction_id', $saleId)->count())->toBe(1);

    $stockAfter = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($stockAfter)->toEqual($stockBefore);
});
