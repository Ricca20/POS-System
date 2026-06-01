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
| `GET /api/v1/pos/config` and `GET /api/v1/pos/products` (Pest, task 5.1)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. GET /pos/config without `sell.create` → canonical 403 envelope.
|   2. GET /pos/config with permission → 200 with the full envelope:
|      every documented top-level key is present, payment_types contains
|      the canonical key set, locations are scoped to the auth business
|      (cross-business locations excluded), tax_rates only include the
|      auth business' tax rows, walk_in_customer mirrors the seeded
|      default contact, default_datetime parses as ISO8601, and
|      keyboard_shortcuts / pos_settings come back as parsed objects.
|   3. GET /pos/products?location_id=<own> → 200 with the featured-
|      products array, including variation rows with their stock summed
|      for that location.
|   4. GET /pos/products?location_id=<other-business> → canonical 404.
|   5. GET /pos/products without location_id → canonical 422 envelope.
|   6. GET /pos/products for a location with empty `featured_products`
|      → 200 with `data: []`.
|
| Bootstrap mirrors `BusinessSettingsTest.php` (Spatie tables stubbed,
| Gate override for permission, Eloquent guardable-cache reflection
| clear) but extends the schema with `tax_rates`, `products`,
| `variations`, `variation_location_details`, `selling_price_groups`,
| and `contacts` so the POS payload can be assembled end-to-end.
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

    // The `business` table contains the `keyboard_shortcuts` and
    // `pos_settings` JSON columns the controller projects, plus the
    // settings subset surfaced by `business_settings`. Other legacy
    // columns are intentionally omitted to keep the test schema tight.
    Schema::create('business', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('currency_id')->unsigned();
        $t->string('time_zone')->default('UTC');
        $t->string('currency_symbol_placement')->default('before');
        $t->integer('currency_precision')->unsigned()->default(2);
        $t->integer('quantity_precision')->unsigned()->default(2);
        $t->boolean('enable_rp')->default(false);
        $t->boolean('enable_brand')->default(true);
        $t->boolean('enable_category')->default(true);
        $t->boolean('enable_sub_category')->default(true);
        $t->boolean('enable_price_tax')->default(true);
        $t->string('sell_price_tax')->default('includes');
        $t->string('date_format')->default('m/d/Y');
        $t->string('time_format')->default('24');
        $t->string('sku_prefix')->nullable();
        $t->integer('transaction_edit_days')->unsigned()->default(30);
        $t->text('keyboard_shortcuts')->nullable();
        $t->text('pos_settings')->nullable();
        $t->text('custom_labels')->nullable();
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

    // Spatie permission tables — see BusinessSettingsTest.php for
    // rationale. Empty tables are sufficient: the Gate::define override
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
        $t->string('landmark')->nullable();
        $t->string('country')->nullable();
        $t->string('state')->nullable();
        $t->string('city')->nullable();
        $t->string('zip_code')->nullable();
        $t->string('mobile')->nullable();
        $t->string('alternate_number')->nullable();
        $t->string('email')->nullable();
        $t->string('website')->nullable();
        $t->string('location_id')->nullable();
        $t->boolean('is_active')->default(true);
        $t->string('receipt_printer_type')->default('browser');
        $t->integer('selling_price_group_id')->unsigned()->nullable();
        $t->integer('invoice_scheme_id')->unsigned()->nullable();
        $t->integer('invoice_layout_id')->unsigned()->nullable();
        $t->json('featured_products')->nullable();
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

    Schema::create('selling_price_groups', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->boolean('is_active')->default(true);
        $t->softDeletes();
        $t->timestamps();
    });

    Schema::create('products', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('name');
        $t->string('type')->default('single');
        $t->string('sku')->nullable();
        $t->integer('unit_id')->unsigned();
        $t->integer('tax')->unsigned()->nullable();
        $t->string('tax_type')->default('inclusive');
        $t->boolean('enable_stock')->default(true);
        $t->text('product_description')->nullable();
        $t->boolean('not_for_selling')->default(false);
        $t->integer('created_by')->unsigned()->nullable();
        $t->string('image')->nullable();
        $t->boolean('is_inactive')->default(false);
        $t->json('sub_unit_ids')->nullable();
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

    // Contacts schema mirrors the columns the walk-in customer lookup
    // touches: `business_id`, `type`, `is_default`, `name`, plus the
    // soft-delete column required by the model trait.
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
        'enable_rp' => false,
        'enable_brand' => true,
        'enable_category' => true,
        'currency_precision' => 2,
        'quantity_precision' => 2,
        'currency_symbol_placement' => 'before',
        'keyboard_shortcuts' => json_encode(['pos_add' => 'F2', 'pos_pay' => 'F3']),
        'pos_settings' => json_encode(['allow_overselling' => false, 'show_pricing_on_button' => true]),
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

    // Eloquent caches column listings statically per model class; clear
    // via reflection so this test's fresh schema is honoured. See
    // `BusinessSettingsTest.php` for the long-form rationale.
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // Seed two locations for the auth business and one for the rival.
    $aliceLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
        'featured_products' => [], // populated per-test below
    ]);

    $aliceEmptyLoc = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme Warehouse (no featured)',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
        'featured_products' => null,
    ]);

    $rivalLoc = BusinessLocation::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Outlet',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
    ]);

    // Two tax rates for the auth business; one for the rival to confirm
    // cross-business isolation.
    $taxId1 = DB::table('tax_rates')->insertGetId([
        'business_id' => $business->id,
        'name' => 'VAT 5%',
        'amount' => 5.0,
        'is_tax_group' => false,
        'for_tax_group' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('tax_rates')->insert([
        'business_id' => $business->id,
        'name' => 'VAT 10%',
        'amount' => 10.0,
        'is_tax_group' => false,
        'for_tax_group' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('tax_rates')->insert([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Tax',
        'amount' => 99.0,
        'is_tax_group' => false,
        'for_tax_group' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('selling_price_groups')->insert([
        'business_id' => $business->id,
        'name' => 'Wholesale',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Walk-in customer for the auth business.
    Contact::create([
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
    test()->aliceEmptyLoc = $aliceEmptyLoc;
    test()->rivalLoc = $rivalLoc;
    test()->taxId1 = $taxId1;

    auth()->forgetGuards();

    if (app()->bound('pos.context')) {
        app()->forgetInstance('pos.context');
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
function loginPosUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant the `sell.create` permission to every authenticated user via
 * Gate. Spatie's Gate::before returns null when the permission row is
 * missing, so Laravel falls through to this defined gate.
 */
function grantSellCreate(): void
{
    Gate::define('sell.create', fn ($user) => true);
}

/**
 * Seed a product + variation pair, optionally with a stock row for the
 * given location. Returns the variation id so callers can wire it into
 * `featured_products`.
 */
function seedFeaturedVariation(
    int $businessId,
    string $name,
    float $sellPriceIncTax,
    ?int $locationId = null,
    float $stock = 0.0
): int {
    $product = Product::create([
        'business_id' => $businessId,
        'name' => $name,
        'type' => 'single',
        'sku' => 'P-'.uniqid(),
        'unit_id' => 1,
        'tax_type' => 'inclusive',
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => 1,
    ]);

    $variation = Variation::create([
        'name' => 'DUMMY',
        'product_id' => $product->id,
        'sub_sku' => 'V-'.uniqid(),
        'default_sell_price' => $sellPriceIncTax,
        'default_sell_price_inc_tax' => $sellPriceIncTax,
    ]);

    if ($locationId !== null) {
        VariationLocationDetails::create([
            'product_id' => $product->id,
            'variation_id' => $variation->id,
            'location_id' => $locationId,
            'qty_available' => $stock,
        ]);
    }

    return $variation->id;
}

it('rejects /pos/config without sell.create permission with a 403 envelope', function () {
    // No `grantSellCreate()` → user has no role/permission.
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/config');

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'forbidden');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('returns the full POS bootstrap envelope on /pos/config with permission', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/config');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // Every documented top-level key must be present.
    $response->assertJsonStructure([
        'data' => [
            'business_id',
            'business_name',
            'business_settings',
            'default_currency' => ['id', 'code', 'symbol', 'thousand_separator', 'decimal_separator'],
            'locations',
            'tax_rates',
            'payment_types',
            'walk_in_customer',
            'default_datetime',
            'keyboard_shortcuts',
            'pos_settings',
            'selling_price_groups',
        ],
    ]);

    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.business_name', 'Acme POS');
    $response->assertJsonPath('data.default_currency.code', 'USD');
    $response->assertJsonPath('data.default_currency.symbol', '$');

    // walk_in_customer mirrors the seeded default contact.
    $response->assertJsonPath('data.walk_in_customer.name', 'Walk In Customer');

    // keyboard_shortcuts and pos_settings come back as parsed objects.
    expect($response->json('data.keyboard_shortcuts'))->toBeArray();
    expect($response->json('data.keyboard_shortcuts.pos_add'))->toBe('F2');
    expect($response->json('data.pos_settings'))->toBeArray();
    expect($response->json('data.pos_settings.allow_overselling'))->toBeFalse();

    // default_datetime is ISO8601-parseable.
    $datetime = $response->json('data.default_datetime');
    expect($datetime)->toBeString();
    expect(strtotime($datetime))->not->toBeFalse();

    // business_settings exposes the documented subset.
    expect($response->json('data.business_settings'))->toHaveKeys([
        'enable_rp',
        'enable_brand',
        'enable_category',
        'currency_precision',
        'quantity_precision',
        'currency_symbol_placement',
    ]);

    // selling_price_groups projects the seeded "Wholesale" row.
    expect($response->json('data.selling_price_groups'))->toHaveCount(1);
    expect($response->json('data.selling_price_groups.0.name'))->toBe('Wholesale');
});

it('scopes /pos/config locations and tax_rates to the auth business', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/config');
    $response->assertStatus(200);

    // Locations: only Alice's two locations, never the rival.
    $locationIds = collect($response->json('data.locations'))->pluck('id')->all();
    expect($locationIds)->toContain(test()->aliceLoc->id);
    expect($locationIds)->toContain(test()->aliceEmptyLoc->id);
    expect($locationIds)->not->toContain(test()->rivalLoc->id);

    // Tax rates: only Alice's two rates, never the rival's.
    $taxNames = collect($response->json('data.tax_rates'))->pluck('name')->all();
    expect($taxNames)->toContain('VAT 5%');
    expect($taxNames)->toContain('VAT 10%');
    expect($taxNames)->not->toContain('Rival Tax');
});

it('returns the canonical payment_types key set on /pos/config', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/config');
    $response->assertStatus(200);

    $keys = collect($response->json('data.payment_types'))->pluck('key')->all();

    // Canonical keys mandated by the task description.
    expect($keys)->toContain('cash');
    expect($keys)->toContain('card');
    expect($keys)->toContain('cheque');
    expect($keys)->toContain('bank_transfer');
    expect($keys)->toContain('advance');
    expect($keys)->toContain('other');
    expect($keys)->toContain('custom_pay_1');
    expect($keys)->toContain('custom_pay_2');
    expect($keys)->toContain('custom_pay_3');
});

it('returns the featured-products array on /pos/products with stock summed for the location', function () {
    grantSellCreate();

    // Seed two variations with stock at the auth location and wire their
    // ids into the location's featured_products column.
    $vid1 = seedFeaturedVariation(test()->business->id, 'Featured Apple', 12.50, test()->aliceLoc->id, 7.0);
    $vid2 = seedFeaturedVariation(test()->business->id, 'Featured Banana', 3.75, test()->aliceLoc->id, 25.5);

    BusinessLocation::where('id', test()->aliceLoc->id)->update([
        'featured_products' => json_encode([$vid1, $vid2]),
    ]);

    loginPosUser();

    $response = $this->getJson('/api/v1/pos/products?location_id='.test()->aliceLoc->id);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'product_id',
                'product_name',
                'image_url',
                'variations' => [
                    '*' => [
                        'variation_id',
                        'sub_sku',
                        'name',
                        'default_sell_price_inc_tax',
                        'current_stock',
                    ],
                ],
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $names = collect($items)->pluck('product_name')->all();
    expect($names)->toContain('Featured Apple');
    expect($names)->toContain('Featured Banana');

    $apple = collect($items)->firstWhere('product_name', 'Featured Apple');
    expect((float) $apple['variations'][0]['current_stock'])->toEqual(7.0);
    expect((float) $apple['variations'][0]['default_sell_price_inc_tax'])->toEqual(12.50);

    $banana = collect($items)->firstWhere('product_name', 'Featured Banana');
    expect((float) $banana['variations'][0]['current_stock'])->toEqual(25.5);
});

it('returns 404 on /pos/products for a cross-business location_id', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/products?location_id='.test()->rivalLoc->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('returns 422 on /pos/products without location_id', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/products');

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['location_id'],
    ]);
});

it('returns an empty data array on /pos/products when the location has no featured_products', function () {
    grantSellCreate();
    loginPosUser();

    $response = $this->getJson('/api/v1/pos/products?location_id='.test()->aliceEmptyLoc->id);

    $response->assertStatus(200);
    expect($response->json('data'))->toBe([]);
});
