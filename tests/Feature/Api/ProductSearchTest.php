<?php

use App\Business;
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
| Product search + pos-row (`/api/v1/products/...`) — Pest, task 4.2
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. `?q=widget` filters variations whose product name OR sub_sku
|      matches LIKE; rival-business products are excluded.
|   2. `?location_id=` restricts results to variations enabled at that
|      location (i.e. with a `variation_location_details` row).
|   3. The 30-result cap is honoured even with a larger candidate set.
|   4. `pos-row` returns 200 with the full cart-row payload including
|      `current_stock`, prices, and `quantity` echoed from the query.
|   5. `pos-row` without `location_id` → 422 (location is required for
|      stock lookup).
|   6. `pos-row` for a variation in another business → 404 (no leak).
|   7. `pos-row` with `quantity > current_stock` (and `enable_stock=true`)
|      still returns 200 but with `in_stock=false` and
|      `available_quantity` set — matches the warn-not-block legacy POS
|      behaviour.
|   8. `pos-row` for a stock-disabled product (`enable_stock=false`)
|      returns `in_stock=true` regardless of stock count.
|
| Bootstrap mirrors `ProductCrudTest.php` and adds the variation-related
| schema this controller path touches.
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

    // Spatie permission tables — see `ProductCrudTest.php` for rationale.
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

    Schema::create('products', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->string('type')->default('single');
        $t->string('sku')->nullable();
        $t->string('barcode_type')->nullable();
        $t->integer('unit_id')->unsigned();
        $t->integer('category_id')->unsigned()->nullable();
        $t->integer('sub_category_id')->unsigned()->nullable();
        $t->integer('brand_id')->unsigned()->nullable();
        $t->integer('tax')->unsigned()->nullable();
        $t->string('tax_type')->default('inclusive');
        $t->boolean('enable_stock')->default(true);
        $t->decimal('alert_quantity', 22, 4)->nullable();
        $t->string('weight')->nullable();
        $t->text('product_description')->nullable();
        $t->boolean('not_for_selling')->default(false);
        $t->integer('created_by')->unsigned()->nullable();
        $t->string('image')->nullable();
        $t->boolean('is_inactive')->default(false);
        $t->json('sub_unit_ids')->nullable();
        $t->timestamps();
    });

    // Variations + variation_location_details: schemas mirror the
    // production columns this controller path projects.
    Schema::create('variations', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('product_id')->unsigned();
        $t->integer('product_variation_id')->unsigned()->default(0);
        $t->string('sub_sku')->nullable();
        $t->decimal('default_purchase_price', 22, 4)->default(0);
        $t->decimal('default_sell_price', 22, 4)->default(0);
        $t->decimal('default_sell_price_inc_tax', 22, 4)->default(0);
        $t->decimal('dpp_inc_tax', 22, 4)->default(0);
        $t->decimal('profit_percent', 5, 2)->default(0);
        $t->json('combo_variations')->nullable();
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

    Schema::create('product_variations', function ($t) {
        $t->increments('id');
        $t->integer('product_id')->unsigned();
        $t->string('name');
        $t->string('variation_template_id')->nullable();
        $t->boolean('is_dummy')->default(false);
        $t->softDeletes();
        $t->timestamps();
    });

    // Selling-price-group tables — eager-loaded by the posRow handler
    // even though no current test exercises the price group path. The
    // empty tables keep Eloquent's eager-load resolver happy.
    Schema::create('selling_price_groups', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->boolean('is_active')->default(true);
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::create('variation_group_prices', function ($t) {
        $t->increments('id');
        $t->integer('variation_id')->unsigned();
        $t->integer('price_group_id')->unsigned();
        $t->decimal('price_inc_tax', 22, 4)->default(0);
        $t->string('price_type')->default('fixed');
        $t->timestamps();
    });

    // Tax-related tables only need to exist for the eager-load resolver
    // (product.product_tax relation isn't used here, but keep schema
    // tight). Skipped — controller does not eager-load it.

    Schema::create('categories', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->integer('parent_id')->unsigned()->default(0);
        $t->string('category_type')->default('product');
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::create('units', function ($t) {
        $t->increments('id');
        $t->string('actual_name');
        $t->string('short_name');
        $t->integer('business_id')->unsigned();
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::create('brands', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->softDeletes();
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

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
    }

    // See `ProductCrudTest.php` for the long-form rationale: clear
    // Eloquent's static `Model::$guardableColumns` cache so this test's
    // fresh in-memory schema is honoured rather than a leaner cache from
    // a prior test in the same Pest run.
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

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
function loginProductSearchUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantProductSearchPermissions(): void
{
    Gate::define('product.view', fn ($user) => true);
}

/**
 * Seed a product + a single variation and (optionally) a stock row at
 * `$locationId`. Returns the variation so tests can address it by id.
 */
function seedProductWithVariation(
    int $businessId,
    array $productAttrs = [],
    array $variationAttrs = [],
    ?int $locationId = null,
    float $stockQty = 0.0
): Variation {
    $product = Product::create(array_merge([
        'business_id' => $businessId,
        'name' => 'Default Widget',
        'type' => 'single',
        'sku' => 'P-'.uniqid(),
        'unit_id' => 1,
        'tax_type' => 'inclusive',
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => 1,
    ], $productAttrs));

    $variation = Variation::create(array_merge([
        'name' => 'DUMMY',
        'product_id' => $product->id,
        'sub_sku' => 'V-'.uniqid(),
        'default_purchase_price' => 5.0,
        'default_sell_price' => 10.0,
        'default_sell_price_inc_tax' => 11.0,
    ], $variationAttrs));

    if ($locationId !== null) {
        VariationLocationDetails::create([
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'location_id' => $locationId,
            'qty_available' => $stockQty,
        ]);
    }

    return $variation;
}

it('filters search results by product name and sub_sku via ?q=', function () {
    grantProductSearchPermissions();

    seedProductWithVariation(test()->business->id, ['name' => 'Blue Widget'], ['sub_sku' => 'BW-001']);
    seedProductWithVariation(test()->business->id, ['name' => 'Red Gadget'], ['sub_sku' => 'RG-001']);
    seedProductWithVariation(test()->business->id, ['name' => 'Spare Part'], ['sub_sku' => 'WIDGET-INTERNAL']);
    // Cross-business: must not appear.
    seedProductWithVariation(test()->otherBusiness->id, ['name' => 'Rival Widget'], ['sub_sku' => 'RV-1']);

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/search?q=widget');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $names = collect($response->json('data'))->pluck('product_name')->all();
    $skus = collect($response->json('data'))->pluck('sub_sku')->all();

    // "Blue Widget" matches on product name; "Spare Part" matches via
    // sub_sku containing WIDGET; "Red Gadget" doesn't match either.
    expect($names)->toContain('Blue Widget');
    expect($skus)->toContain('WIDGET-INTERNAL');
    expect($names)->not->toContain('Red Gadget');
    expect($names)->not->toContain('Rival Widget');
});

it('restricts search to variations enabled at the given location_id', function () {
    grantProductSearchPermissions();

    // Variation with a stock row at location 42 only.
    seedProductWithVariation(
        test()->business->id,
        ['name' => 'Local Widget'],
        ['sub_sku' => 'LW-1'],
        42,
        15.0,
    );
    // Variation with a stock row at a different location only.
    seedProductWithVariation(
        test()->business->id,
        ['name' => 'Remote Widget'],
        ['sub_sku' => 'RW-1'],
        99,
        5.0,
    );
    // Variation with no stock row at all.
    seedProductWithVariation(
        test()->business->id,
        ['name' => 'Orphan Widget'],
        ['sub_sku' => 'OW-1'],
    );

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/search?location_id=42');

    $response->assertStatus(200);
    $names = collect($response->json('data'))->pluck('product_name')->all();

    expect($names)->toContain('Local Widget');
    expect($names)->not->toContain('Remote Widget');
    expect($names)->not->toContain('Orphan Widget');

    // Stock value comes from the location-scoped row. JSON encodes
    // whole floats as integers (`15`), so use loose equality.
    $row = collect($response->json('data'))->firstWhere('product_name', 'Local Widget');
    expect((float) $row['current_stock'])->toEqual(15.0);
});

it('caps search results at 30 even with a larger candidate set', function () {
    grantProductSearchPermissions();

    for ($i = 1; $i <= 35; $i++) {
        seedProductWithVariation(
            test()->business->id,
            ['name' => 'Bulk Widget '.$i, 'sku' => 'BULK-'.$i],
            ['sub_sku' => 'BULK-V-'.$i],
        );
    }

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/search?q=bulk');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(30);
});

it('returns the full cart-row payload on pos-row for a stocked variation', function () {
    grantProductSearchPermissions();

    $variation = seedProductWithVariation(
        test()->business->id,
        [
            'name' => 'POS Widget',
            'enable_stock' => true,
            'tax' => 7,
            'tax_type' => 'inclusive',
        ],
        [
            'sub_sku' => 'POS-1',
            'default_sell_price' => 20.0,
            'default_sell_price_inc_tax' => 22.0,
            'default_purchase_price' => 12.0,
        ],
        42,
        50.0,
    );

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/'.$variation->id.'/pos-row?location_id=42&quantity=3');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'variation_id',
            'product_id',
            'product_name',
            'sub_sku',
            'full_name',
            'unit_id',
            'tax_rate_id',
            'tax_type',
            'default_sell_price',
            'default_sell_price_inc_tax',
            'default_purchase_price',
            'current_stock',
            'enable_stock',
            'quantity',
            'in_stock',
        ],
    ]);

    $response->assertJsonPath('data.variation_id', $variation->id);
    $response->assertJsonPath('data.product_name', 'POS Widget');
    $response->assertJsonPath('data.sub_sku', 'POS-1');
    $response->assertJsonPath('data.tax_rate_id', 7);
    $response->assertJsonPath('data.tax_type', 'inclusive');
    $response->assertJsonPath('data.enable_stock', true);
    $response->assertJsonPath('data.in_stock', true);

    // Whole-number floats decode as JSON integers; compare loosely.
    expect((float) $response->json('data.default_sell_price'))->toEqual(20.0);
    expect((float) $response->json('data.default_sell_price_inc_tax'))->toEqual(22.0);
    expect((float) $response->json('data.default_purchase_price'))->toEqual(12.0);
    expect((float) $response->json('data.current_stock'))->toEqual(50.0);
    expect((float) $response->json('data.quantity'))->toEqual(3.0);

    // full_name accessor: "POS Widget (POS-1)" for type='single'.
    $response->assertJsonPath('data.full_name', 'POS Widget (POS-1)');

    // Happy path must NOT carry an `available_quantity` warning key.
    expect($response->json('data'))->not->toHaveKey('available_quantity');
});

it('returns 422 when pos-row is called without location_id', function () {
    grantProductSearchPermissions();

    $variation = seedProductWithVariation(test()->business->id);

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/'.$variation->id.'/pos-row');

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['location_id'],
    ]);
});

it('returns 404 on pos-row for a variation in a different business', function () {
    grantProductSearchPermissions();

    $rivalVariation = seedProductWithVariation(
        test()->otherBusiness->id,
        ['name' => 'Rival Hidden'],
        ['sub_sku' => 'RH-1'],
        42,
        10.0,
    );

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/'.$rivalVariation->id.'/pos-row?location_id=42');

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('flags over-sell with in_stock=false and available_quantity on pos-row', function () {
    grantProductSearchPermissions();

    $variation = seedProductWithVariation(
        test()->business->id,
        ['name' => 'Scarce Widget', 'enable_stock' => true],
        ['sub_sku' => 'SCARCE-1'],
        42,
        2.0,  // current stock
    );

    loginProductSearchUser();

    // Asking for 5 when only 2 exist — must succeed (warn, not block).
    $response = $this->getJson('/api/v1/products/'.$variation->id.'/pos-row?location_id=42&quantity=5');

    $response->assertStatus(200);
    $response->assertJsonPath('data.in_stock', false);
    expect((float) $response->json('data.current_stock'))->toEqual(2.0);
    expect((float) $response->json('data.available_quantity'))->toEqual(2.0);
    expect((float) $response->json('data.quantity'))->toEqual(5.0);
});

it('reports in_stock=true on pos-row when the product has enable_stock=false', function () {
    grantProductSearchPermissions();

    $variation = seedProductWithVariation(
        test()->business->id,
        ['name' => 'Service Item', 'enable_stock' => false],
        ['sub_sku' => 'SVC-1'],
        // No stock row needed: stock is disabled.
    );

    loginProductSearchUser();

    $response = $this->getJson('/api/v1/products/'.$variation->id.'/pos-row?location_id=42&quantity=999');

    $response->assertStatus(200);
    $response->assertJsonPath('data.enable_stock', false);
    $response->assertJsonPath('data.in_stock', true);
    expect((float) $response->json('data.current_stock'))->toEqual(0.0);

    // No warning key on the happy path even though qty is large —
    // stock-disabled products are always sellable.
    expect($response->json('data'))->not->toHaveKey('available_quantity');
});
