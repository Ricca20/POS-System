<?php

use App\Brands;
use App\Business;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/brands` and `/api/v1/brands/{id}` (Pest, task 4.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated GET on the index returns 200 + only the
|      authenticated business's brands.
|   2. Authenticated GET on a valid id returns 200 with the full
|      BrandResource shape.
|   3. GET on an id belonging to a different business returns 404.
|   4. Unauthenticated GET returns the canonical 401 envelope.
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

    // The legacy `brands` table is the singular companion to the
    // `App\Brands` model. The Repair module migration adds
    // `use_for_repair`. SoftDeletes is used by the model.
    Schema::create('brands', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->text('description')->nullable();
        $t->boolean('use_for_repair')->default(false);
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

    $ref = new \ReflectionClass(\Illuminate\Database\Eloquent\Model::class);
    if ($ref->hasProperty('guardableColumns')) {
        $prop = $ref->getProperty('guardableColumns');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    $aliceBrand1 = Brands::create([
        'business_id' => $business->id,
        'name' => 'Acme Originals',
        'description' => 'House brand',
        'use_for_repair' => false,
    ]);
    $aliceBrand2 = Brands::create([
        'business_id' => $business->id,
        'name' => 'Acme Service',
        'description' => 'Repair tooling',
        'use_for_repair' => true,
    ]);
    $rivalBrand = Brands::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Brand',
        'description' => 'Hidden',
        'use_for_repair' => false,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceBrand1 = $aliceBrand1;
    test()->aliceBrand2 = $aliceBrand2;
    test()->rivalBrand = $rivalBrand;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginBrandUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

it('returns only the brands belonging to the auth business', function () {
    loginBrandUser();

    $response = $this->getJson('/api/v1/brands');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'business_id',
                'description',
                'use_for_repair',
                'created_at',
                'updated_at',
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $ids = collect($items)->pluck('id')->all();
    expect($ids)->toContain(test()->aliceBrand1->id);
    expect($ids)->toContain(test()->aliceBrand2->id);
    expect($ids)->not->toContain(test()->rivalBrand->id);

    $service = collect($items)->firstWhere('id', test()->aliceBrand2->id);
    expect($service['use_for_repair'])->toBeTrue();
});

it('returns the full BrandResource shape on show for an own brand', function () {
    loginBrandUser();

    $response = $this->getJson('/api/v1/brands/'.test()->aliceBrand1->id);

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', test()->aliceBrand1->id);
    $response->assertJsonPath('data.name', 'Acme Originals');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.use_for_repair', false);
});

it('returns 404 when the brand id belongs to a different business', function () {
    loginBrandUser();

    $response = $this->getJson('/api/v1/brands/'.test()->rivalBrand->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/brands');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
});
