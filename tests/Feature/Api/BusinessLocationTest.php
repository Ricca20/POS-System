<?php

use App\Business;
use App\BusinessLocation;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/business/locations` and `/api/v1/business/locations/{id}`
| (Pest, task 3.2)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated GET on the index returns 200 + the BusinessLocation
|      collection containing only locations for the auth user's business.
|   2. Authenticated GET on a valid id (within the auth business) returns
|      200 with the full BusinessLocationResource shape.
|   3. GET on an id belonging to a *different* business returns 404 with
|      the canonical `not_found` envelope. This is the cross-business
|      data-leak guard — failure here is a security-critical regression.
|   4. GET on a non-existent id returns 404.
|   5. Unauthenticated GET returns the canonical 401 envelope.
|
| Bootstrap mirrors `BusinessSettingsTest.php` but trims the schema to
| only the tables this controller pipeline touches: `currencies`,
| `business`, `users`, `personal_access_tokens`, plus a fresh
| `business_locations` table built to match the production columns
| `BusinessLocationResource` exposes.
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

    // Production `business_locations` columns the resource and controller
    // touch. The legacy Laravel migration includes a few additional fields
    // (default_payment_accounts, sale_invoice_scheme_id, custom_field_*)
    // that this leaf task does not expose, so they are intentionally
    // omitted to keep the test schema tight.
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

    // A second, distinct business so we can prove cross-business reads
    // are blocked. Its location must NEVER appear in the auth user's
    // index response or be readable by id.
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

    // Eloquent caches the column listing per model class in a static
    // array (`Model::$guardableColumns`) the first time `isGuarded()` is
    // called. That cache is keyed by class name and never refreshed,
    // so a previous test in the same Pest run that booted any model
    // against a leaner schema leaves the cache pinned. Subsequent fills
    // against our richer schema would silently drop columns the cache
    // doesn't list. Clear via reflection — there is no public API for
    // this in Laravel 9.
    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // Two locations for Alice's business — the index endpoint must
    // return exactly these two.
    $aliceLoc1 = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
        'landmark' => '1 Acme Plaza',
        'country' => 'United States',
        'state' => 'CA',
        'city' => 'San Francisco',
        'zip_code' => '94105',
        'mobile' => '555-0100',
        'alternate_number' => '555-0101',
        'email' => 'hq@acme.example',
        'website' => 'https://acme.example',
        'location_id' => 'BL0001',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
        'selling_price_group_id' => null,
        'invoice_scheme_id' => null,
        'invoice_layout_id' => null,
        'featured_products' => [101, 102, 103],
    ]);

    $aliceLoc2 = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme Warehouse',
        'country' => 'United States',
        'city' => 'Oakland',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
    ]);

    // One location for the unrelated business — must be invisible to
    // Alice through every endpoint in this controller.
    $rivalLoc = BusinessLocation::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Outlet',
        'country' => 'United States',
        'city' => 'Los Angeles',
        'is_active' => true,
        'receipt_printer_type' => 'browser',
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceLoc1 = $aliceLoc1;
    test()->aliceLoc2 = $aliceLoc2;
    test()->rivalLoc = $rivalLoc;

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
function loginLocationUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

it('returns only the locations belonging to the authenticated user business', function () {
    loginLocationUser();

    $response = $this->getJson('/api/v1/business/locations');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'business_id',
                'landmark',
                'country',
                'state',
                'city',
                'zip_code',
                'mobile',
                'alternate_number',
                'email',
                'website',
                'location_id',
                'is_active',
                'receipt_printer_type',
                'selling_price_group_id',
                'invoice_scheme_id',
                'invoice_layout_id',
                'featured_products',
                'created_at',
                'updated_at',
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    // Sanity: every returned id belongs to Alice's business and the
    // rival business location is NOT in the payload.
    $ids = collect($items)->pluck('id')->all();
    expect($ids)->toContain(test()->aliceLoc1->id);
    expect($ids)->toContain(test()->aliceLoc2->id);
    expect($ids)->not->toContain(test()->rivalLoc->id);

    // Spot-check resource field projection on the first record.
    $first = collect($items)->firstWhere('id', test()->aliceLoc1->id);
    expect($first['name'])->toBe('Acme HQ');
    expect($first['business_id'])->toBe(test()->business->id);
    expect($first['country'])->toBe('United States');
    expect($first['city'])->toBe('San Francisco');
    expect($first['location_id'])->toBe('BL0001');
    expect($first['is_active'])->toBeTrue();
    expect($first['receipt_printer_type'])->toBe('browser');
    expect($first['featured_products'])->toEqualCanonicalizing([101, 102, 103]);
});

it('returns a single location when the id belongs to the auth business', function () {
    loginLocationUser();

    $response = $this->getJson('/api/v1/business/locations/'.test()->aliceLoc1->id);

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'id',
            'name',
            'business_id',
            'landmark',
            'country',
            'state',
            'city',
            'zip_code',
            'mobile',
            'alternate_number',
            'email',
            'website',
            'location_id',
            'is_active',
            'receipt_printer_type',
            'selling_price_group_id',
            'invoice_scheme_id',
            'invoice_layout_id',
            'featured_products',
            'created_at',
            'updated_at',
        ],
    ]);

    $response->assertJsonPath('data.id', test()->aliceLoc1->id);
    $response->assertJsonPath('data.name', 'Acme HQ');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.is_active', true);
});

it('returns 404 when the location id belongs to a different business', function () {
    loginLocationUser();

    // Critical security invariant: a token issued for business A may not
    // read a location id owned by business B. Returning 404 (rather than
    // 403) prevents existence-leak about other businesses' data.
    $response = $this->getJson('/api/v1/business/locations/'.test()->rivalLoc->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('returns 404 for a completely non-existent location id', function () {
    loginLocationUser();

    $response = $this->getJson('/api/v1/business/locations/9999');

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/business/locations');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
});
