<?php

use App\Business;
use App\Category;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/categories` and `/api/v1/categories/{id}` (Pest, task 4.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated GET on the index returns 200 + only the
|      authenticated business's product categories (default `?type=product`).
|   2. `?type=tax` filter returns only tax-typed categories.
|   3. Authenticated GET on a valid id returns 200 with the full
|      CategoryResource shape.
|   4. GET on an id belonging to a different business returns 404 with
|      the canonical `not_found` envelope (cross-business leak guard).
|   5. Unauthenticated GET returns the canonical 401 envelope.
|
| Bootstrap mirrors `BusinessLocationTest.php` and `ProductCrudTest.php`,
| trimmed to the schema this controller pipeline touches.
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

    // Categories table mirrors the production columns the controller and
    // resource touch. Includes `softDeletes()` so the `Category` model's
    // SoftDeletes trait can issue its `WHERE deleted_at IS NULL` scope
    // without raising a SQL error.
    Schema::create('categories', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->integer('parent_id')->unsigned()->default(0);
        $t->string('category_type')->default('product');
        $t->string('short_code')->nullable();
        $t->text('description')->nullable();
        $t->string('slug')->nullable();
        $t->integer('woocommerce_cat_id')->unsigned()->nullable();
        $t->softDeletes();
        $t->timestamps();
    });

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

    // Clear Eloquent's static `Model::$guardableColumns` cache so the
    // fresh in-memory schema is honoured rather than a leaner cache from
    // a prior test in the same Pest run. See BusinessSettingsTest for
    // the long-form rationale.
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // Two product categories + one tax category in Alice's business,
    // plus a product category in the rival business. The `?type=`
    // filter test relies on each type being represented.
    $aliceCat1 = Category::create([
        'business_id' => $business->id,
        'name' => 'Beverages',
        'parent_id' => 0,
        'category_type' => 'product',
        'short_code' => 'BEV',
        'description' => 'Drinks and refreshments',
        'slug' => 'beverages',
        'woocommerce_cat_id' => 9001,
    ]);

    $aliceCat2 = Category::create([
        'business_id' => $business->id,
        'name' => 'Snacks',
        'parent_id' => 0,
        'category_type' => 'product',
    ]);

    $aliceTaxCat = Category::create([
        'business_id' => $business->id,
        'name' => 'VAT 5%',
        'parent_id' => 0,
        'category_type' => 'tax',
    ]);

    $rivalCat = Category::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Hidden Cat',
        'parent_id' => 0,
        'category_type' => 'product',
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceCat1 = $aliceCat1;
    test()->aliceCat2 = $aliceCat2;
    test()->aliceTaxCat = $aliceTaxCat;
    test()->rivalCat = $rivalCat;

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
function loginCategoryUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

it('returns only product categories for the auth business by default', function () {
    loginCategoryUser();

    $response = $this->getJson('/api/v1/categories');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'business_id',
                'parent_id',
                'category_type',
                'short_code',
                'description',
                'slug',
                'woocommerce_cat_id',
                'created_at',
                'updated_at',
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $ids = collect($items)->pluck('id')->all();
    expect($ids)->toContain(test()->aliceCat1->id);
    expect($ids)->toContain(test()->aliceCat2->id);
    // Tax-typed and cross-business rows are excluded by default.
    expect($ids)->not->toContain(test()->aliceTaxCat->id);
    expect($ids)->not->toContain(test()->rivalCat->id);

    $beverages = collect($items)->firstWhere('id', test()->aliceCat1->id);
    expect($beverages['name'])->toBe('Beverages');
    expect($beverages['business_id'])->toBe(test()->business->id);
    expect($beverages['category_type'])->toBe('product');
    expect($beverages['short_code'])->toBe('BEV');
    expect($beverages['slug'])->toBe('beverages');
    expect($beverages['woocommerce_cat_id'])->toBe(9001);
});

it('filters by ?type=tax to return only tax categories', function () {
    loginCategoryUser();

    $response = $this->getJson('/api/v1/categories?type=tax');

    $response->assertStatus(200);

    $items = $response->json('data');
    expect($items)->toHaveCount(1);

    $first = $items[0];
    expect($first['id'])->toBe(test()->aliceTaxCat->id);
    expect($first['category_type'])->toBe('tax');
    expect($first['name'])->toBe('VAT 5%');
});

it('returns the full CategoryResource shape on show for an own category', function () {
    loginCategoryUser();

    $response = $this->getJson('/api/v1/categories/'.test()->aliceCat1->id);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'business_id',
            'parent_id',
            'category_type',
            'short_code',
            'description',
            'slug',
            'woocommerce_cat_id',
            'created_at',
            'updated_at',
        ],
    ]);

    $response->assertJsonPath('data.id', test()->aliceCat1->id);
    $response->assertJsonPath('data.name', 'Beverages');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.category_type', 'product');
});

it('returns 404 when the category id belongs to a different business', function () {
    loginCategoryUser();

    $response = $this->getJson('/api/v1/categories/'.test()->rivalCat->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/categories');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
});
