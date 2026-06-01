<?php

use App\Business;
use App\Product;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Product CRUD (`/api/v1/products`) — Pest, task 4.1
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. GET index without `product.view` returns 403.
|   2. GET index with `product.view` returns 200 + paginated empty list.
|   3. Cross-business isolation: 3 products in own business + 1 in other
|      business, GET index returns exactly the 3 own-business rows.
|   4. `?q=widget` filters name/sku LIKE.
|   5. `?per_page=2&page=2` paginates correctly.
|   6. GET show for own product returns 200 with full ProductResource shape.
|   7. GET show for other-business product returns 404.
|   8. POST with valid body returns 201, DB row exists.
|   9. POST with invalid body (missing `name`) returns 422.
|  10. PUT partial body returns 200, fields updated, untouched fields kept.
|  11. DELETE for own returns 204, row deleted.
|  12. DELETE for other-business returns 404.
|
| Bootstrap pattern mirrors `BusinessLocationTest.php`. Permission gating
| uses the `Gate::define` override pattern from `BusinessSettingsTest`:
| Spatie's `Gate::before` returns null when the permission row is missing,
| so Laravel falls through to gates defined inline in the test.
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

    // Spatie permission tables — see `BusinessSettingsTest.php` for the
    // long-form rationale. Empty tables let `Gate::before` return null,
    // so the test-defined gates take effect.
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

    // Production `products` table columns the controller and resource
    // touch, plus the few legacy nullable columns Eloquent's mass-assign
    // path will write through `$guarded = ['id']`. Variations, location
    // attachments, and image upload aren't covered by this leaf task,
    // so the schema is intentionally tight.
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

    // Relation tables eager-loaded by the controller (`category`,
    // `unit`, `brand`). The relation models all use Laravel's
    // `SoftDeletes` trait, so each table needs `deleted_at` to avoid
    // SQL errors when the controller's eager-load resolver issues its
    // pre-fetch query. Test rows are never inserted; the empty tables
    // satisfy the resolver and keep the resource's `whenLoaded`
    // projections returning `null`.
    Schema::create('categories', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->integer('parent_id')->unsigned()->default(0);
        $t->string('category_type')->default('product');
        $t->string('short_code')->nullable();
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::create('units', function ($t) {
        $t->increments('id');
        $t->string('actual_name');
        $t->string('short_name');
        $t->integer('business_id')->unsigned();
        $t->integer('base_unit_id')->unsigned()->nullable();
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

    // See `BusinessSettingsTest.php` for the long-form rationale: clear
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
function loginProductUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

/**
 * Grant all four product permissions via Gate::define.
 *
 * Spatie's `Gate::before` callback returns `null` when the permission
 * row is missing, so Laravel falls through to gates defined here. This
 * keeps the test from having to seed Spatie role/permission pivot rows.
 */
function grantProductPermissions(): void
{
    Gate::define('product.view', fn ($user) => true);
    Gate::define('product.create', fn ($user) => true);
    Gate::define('product.update', fn ($user) => true);
    Gate::define('product.delete', fn ($user) => true);
}

/**
 * Helper that builds a product directly via the Eloquent model so the
 * tests can prime the table without going through the API.
 */
function makeProduct(int $businessId, array $attrs = []): Product
{
    return Product::create(array_merge([
        'business_id' => $businessId,
        'name' => 'Default Widget',
        'type' => 'single',
        'sku' => 'SKU-'.uniqid(),
        'barcode_type' => 'C128',
        'unit_id' => 1,
        'tax_type' => 'inclusive',
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => 1,
    ], $attrs));
}

it('returns 403 when listing products without product.view permission', function () {
    loginProductUser();

    $response = $this->getJson('/api/v1/products');

    $response->assertStatus(403);
    $response->assertJsonPath('code', 'forbidden');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('returns a 200 paginated empty list when no products exist', function () {
    grantProductPermissions();
    loginProductUser();

    $response = $this->getJson('/api/v1/products');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // ProductResource::collection on a paginator emits `data`, `links`,
    // and `meta` automatically.
    $response->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);

    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
});

it('lists only the authenticated business products (cross-business isolation)', function () {
    grantProductPermissions();

    $own1 = makeProduct(test()->business->id, ['name' => 'Own One', 'sku' => 'O-1']);
    $own2 = makeProduct(test()->business->id, ['name' => 'Own Two', 'sku' => 'O-2']);
    $own3 = makeProduct(test()->business->id, ['name' => 'Own Three', 'sku' => 'O-3']);
    $rival = makeProduct(test()->otherBusiness->id, ['name' => 'Rival', 'sku' => 'R-1']);

    loginProductUser();

    $response = $this->getJson('/api/v1/products');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($own1->id);
    expect($ids)->toContain($own2->id);
    expect($ids)->toContain($own3->id);
    expect($ids)->not->toContain($rival->id);
});

it('filters products by name/sku via ?q=', function () {
    grantProductPermissions();

    makeProduct(test()->business->id, ['name' => 'Blue Widget', 'sku' => 'BW-001']);
    makeProduct(test()->business->id, ['name' => 'Red Gadget', 'sku' => 'RG-001']);
    makeProduct(test()->business->id, ['name' => 'Green Widget', 'sku' => 'GW-001']);

    loginProductUser();

    $response = $this->getJson('/api/v1/products?q=widget');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(2);

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('Blue Widget');
    expect($names)->toContain('Green Widget');
    expect($names)->not->toContain('Red Gadget');
});

it('paginates with per_page and page query parameters', function () {
    grantProductPermissions();

    makeProduct(test()->business->id, ['name' => 'A', 'sku' => 'A']);
    makeProduct(test()->business->id, ['name' => 'B', 'sku' => 'B']);
    makeProduct(test()->business->id, ['name' => 'C', 'sku' => 'C']);

    loginProductUser();

    $response = $this->getJson('/api/v1/products?per_page=2&page=2');

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.current_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(1);
});

it('returns the full ProductResource shape on show for own product', function () {
    grantProductPermissions();

    $product = makeProduct(test()->business->id, [
        'name' => 'Showcase Widget',
        'sku' => 'SHOW-1',
        'barcode_type' => 'C128',
        'tax_type' => 'inclusive',
        'enable_stock' => true,
        'not_for_selling' => false,
        'alert_quantity' => '5.0000',
    ]);

    loginProductUser();

    $response = $this->getJson('/api/v1/products/'.$product->id);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'business_id',
            'type',
            'sku',
            'barcode_type',
            'enable_stock',
            'alert_quantity',
            'not_for_selling',
            'unit_id',
            'category_id',
            'sub_category_id',
            'brand_id',
            'tax',
            'tax_type',
            'weight',
            'product_description',
            'created_by',
            'created_at',
            'updated_at',
        ],
    ]);

    $response->assertJsonPath('data.id', $product->id);
    $response->assertJsonPath('data.name', 'Showcase Widget');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.sku', 'SHOW-1');
    $response->assertJsonPath('data.enable_stock', true);
    $response->assertJsonPath('data.not_for_selling', false);
    $response->assertJsonPath('data.tax_type', 'inclusive');

    // Sensitive/legacy keys must NOT leak through the resource.
    $payload = $response->json('data');
    expect($payload)->not->toHaveKey('image');
    expect($payload)->not->toHaveKey('image_url');
    expect($payload)->not->toHaveKey('is_inactive');
    expect($payload)->not->toHaveKey('sub_unit_ids');
});

it('returns 404 when showing a product belonging to another business', function () {
    grantProductPermissions();

    $rival = makeProduct(test()->otherBusiness->id, ['name' => 'Rival Hidden']);

    loginProductUser();

    $response = $this->getJson('/api/v1/products/'.$rival->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('creates a product with a valid POST body and returns 201', function () {
    grantProductPermissions();
    loginProductUser();

    $response = $this->postJson('/api/v1/products', [
        'name' => 'New Widget',
        'type' => 'single',
        'unit_id' => 1,
        'sku' => 'NEW-1',
        'barcode_type' => 'C128',
        'tax_type' => 'inclusive',
        'enable_stock' => true,
        'not_for_selling' => false,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.name', 'New Widget');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.created_by', test()->user->id);

    $newId = $response->json('data.id');
    $row = DB::table('products')->where('id', $newId)->first();
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('New Widget');
    expect((int) $row->business_id)->toBe(test()->business->id);
    expect((int) $row->created_by)->toBe(test()->user->id);
});

it('returns 422 with the canonical envelope when name is missing', function () {
    grantProductPermissions();
    loginProductUser();

    $response = $this->postJson('/api/v1/products', [
        // no `name`
        'type' => 'single',
        'unit_id' => 1,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['name'],
    ]);
});

it('updates a product partially via PUT and reflects the change in DB', function () {
    grantProductPermissions();

    $product = makeProduct(test()->business->id, [
        'name' => 'Original Name',
        'sku' => 'ORIG-1',
        'tax_type' => 'inclusive',
    ]);

    loginProductUser();

    $response = $this->putJson('/api/v1/products/'.$product->id, [
        'name' => 'Renamed Widget',
        'tax_type' => 'exclusive',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', $product->id);
    $response->assertJsonPath('data.name', 'Renamed Widget');
    $response->assertJsonPath('data.tax_type', 'exclusive');

    $row = DB::table('products')->where('id', $product->id)->first();
    expect($row->name)->toBe('Renamed Widget');
    expect($row->tax_type)->toBe('exclusive');
    // Sanity: untouched columns kept their original values.
    expect($row->sku)->toBe('ORIG-1');
});

it('deletes a product the auth business owns and returns 204', function () {
    grantProductPermissions();

    $product = makeProduct(test()->business->id);

    loginProductUser();

    $response = $this->deleteJson('/api/v1/products/'.$product->id);

    $response->assertStatus(204);
    expect((string) $response->getContent())->toBe('');

    $row = DB::table('products')->where('id', $product->id)->first();
    expect($row)->toBeNull();
});

it('returns 404 when deleting a product from another business', function () {
    grantProductPermissions();

    $rival = makeProduct(test()->otherBusiness->id);

    loginProductUser();

    $response = $this->deleteJson('/api/v1/products/'.$rival->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');

    // The rival row is still present.
    $row = DB::table('products')->where('id', $rival->id)->first();
    expect($row)->not->toBeNull();
});
