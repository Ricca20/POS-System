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
| `SellReturnApiController` (Pest, task 5.5)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   a) Authenticated POST /api/v1/sell-returns with a valid body -> 201,
|      a `transactions` row is created with `type='sell_return'`,
|      `return_parent_id` linking to the parent sale, and
|      `variation_location_details.qty_available` is incremented at the
|      parent's location for every returned line.
|   b) GET /api/v1/sell-returns lists own-business returns;
|      cross-business returns are excluded.
|   c) POST /api/v1/sell-returns with a `parent_sale_id` from another
|      business -> 422 with `errors.parent_sale_id` (the form request's
|      `Rule::exists` scope rejects the cross-business id).
|   d) Without `access_sell_return` permission -> 403.
|
| Bootstrap matches `SaleListTest.php` (full SQLite schema with
| `is_quotation` + `return_parent_id` on the transactions table).
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

function loginSellReturnUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantAccessSellReturn(): void
{
    Gate::define('access_sell_return', fn ($user) => true);
}

/**
 * Insert a parent `sell` transaction and return its id. Defaults align
 * with `SaleListTest::seedSale`.
 */
function seedParentSale(array $overrides = []): int
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
        'invoice_no' => 'INV-PARENT',
        'total_before_tax' => 50,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 50,
        'is_direct_sale' => 0,
        'is_quotation' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return (int) DB::table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('creates a sell return tied to the parent sale and increments stock at the parent location', function () {
    grantAccessSellReturn();
    loginSellReturnUser();

    $parentId = seedParentSale();

    $stockBefore = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');

    $response = $this->postJson('/api/v1/sell-returns', [
        'parent_sale_id' => $parentId,
        'transaction_date' => '2024-03-15T09:00:00',
        'additional_notes' => 'Customer changed their mind.',
        'products' => [
            [
                'product_id' => test()->product1->id,
                'variation_id' => test()->variation1->id,
                'quantity' => 2,
                'unit_price' => 10,
            ],
        ],
    ]);

    $response->assertStatus(201);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $returnId = (int) $response->json('data.id');
    expect($returnId)->toBeGreaterThan(0);

    // Persisted return row.
    $row = DB::table('transactions')->where('id', $returnId)->first();
    expect($row->type)->toBe('sell_return');
    expect((int) $row->return_parent_id)->toBe($parentId);
    expect((int) $row->business_id)->toBe(test()->business->id);
    expect((int) $row->location_id)->toBe(test()->aliceLoc->id);
    expect((int) $row->contact_id)->toBe(test()->walkIn->id);
    expect((float) $row->final_total)->toEqual(20.0);

    // The sell_lines were copied with positive quantity.
    $lines = DB::table('transaction_sell_lines')->where('transaction_id', $returnId)->get();
    expect($lines)->toHaveCount(1);
    expect((float) $lines[0]->quantity)->toEqual(2.0);

    // Stock was incremented at the parent's location.
    $stockAfter = (float) DB::table('variation_location_details')
        ->where('variation_id', test()->variation1->id)
        ->where('location_id', test()->aliceLoc->id)
        ->value('qty_available');
    expect($stockAfter - $stockBefore)->toEqual(2.0);
});

it('lists own-business returns and excludes cross-business rows', function () {
    grantAccessSellReturn();
    loginSellReturnUser();

    $parentId = seedParentSale();

    // Own-business return.
    $ownReturnId = (int) DB::table('transactions')->insertGetId([
        'business_id' => test()->business->id,
        'location_id' => test()->aliceLoc->id,
        'type' => 'sell_return',
        'status' => 'final',
        'payment_status' => 'paid',
        'return_parent_id' => $parentId,
        'transaction_date' => '2024-03-20 09:00:00',
        'contact_id' => test()->walkIn->id,
        'invoice_no' => 'RET-OWN',
        'total_before_tax' => 10,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 10,
        'is_direct_sale' => 0,
        'is_quotation' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Foreign-business return must NOT appear.
    DB::table('transactions')->insertGetId([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'type' => 'sell_return',
        'status' => 'final',
        'payment_status' => 'paid',
        'return_parent_id' => null,
        'transaction_date' => '2024-03-20 09:00:00',
        'contact_id' => null,
        'invoice_no' => 'RET-RIVAL',
        'total_before_tax' => 10,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 10,
        'is_direct_sale' => 0,
        'is_quotation' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/sell-returns');
    $response->assertStatus(200);

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['id'])->toBe($ownReturnId);
    expect((int) $rows[0]['business_id'])->toBe(test()->business->id);
});

it('returns 422 with errors.parent_sale_id when parent_sale_id belongs to another business', function () {
    grantAccessSellReturn();
    loginSellReturnUser();

    // Foreign-business sale.
    $foreignParentId = (int) DB::table('transactions')->insertGetId([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'type' => 'sell',
        'sub_type' => 'pos',
        'status' => 'final',
        'payment_status' => 'paid',
        'transaction_date' => '2024-03-10 09:00:00',
        'contact_id' => null,
        'invoice_no' => 'INV-RIVAL',
        'total_before_tax' => 10,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 10,
        'is_direct_sale' => 0,
        'is_quotation' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/sell-returns', [
        'parent_sale_id' => $foreignParentId,
        'transaction_date' => '2024-03-15T09:00:00',
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
        'errors' => ['parent_sale_id'],
    ]);

    // No `sell_return` row was created.
    expect(DB::table('transactions')->where('type', 'sell_return')->count())->toBe(0);
});

it('returns 403 when the user lacks the access_sell_return permission', function () {
    // No `grantAccessSellReturn()` call.
    loginSellReturnUser();

    // GET should also 403.
    $listResponse = $this->getJson('/api/v1/sell-returns');
    $listResponse->assertStatus(403);
    expect($listResponse->headers->get('Content-Type'))->toStartWith('application/json');

    $parentId = seedParentSale();

    $response = $this->postJson('/api/v1/sell-returns', [
        'parent_sale_id' => $parentId,
        'transaction_date' => '2024-03-15T09:00:00',
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

    // No `sell_return` row was created.
    expect(DB::table('transactions')->where('type', 'sell_return')->count())->toBe(0);
});
