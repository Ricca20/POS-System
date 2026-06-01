<?php

use App\Business;
use App\Unit;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/units` and `/api/v1/units/{id}` (Pest, task 4.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated GET on the index returns 200 + only the
|      authenticated business's units (both base and sub units).
|   2. Authenticated GET on a valid id returns 200 with the full
|      UnitResource shape.
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

    // The `units` table includes the production columns the resource
    // surfaces. SoftDeletes is used by the model.
    Schema::create('units', function ($t) {
        $t->increments('id');
        $t->string('actual_name');
        $t->string('short_name');
        $t->integer('business_id')->unsigned();
        $t->boolean('allow_decimal')->default(false);
        $t->integer('base_unit_id')->unsigned()->nullable();
        $t->decimal('base_unit_multiplier', 22, 4)->nullable();
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

    $kg = Unit::create([
        'business_id' => $business->id,
        'actual_name' => 'Kilogram',
        'short_name' => 'kg',
        'allow_decimal' => true,
    ]);
    $g = Unit::create([
        'business_id' => $business->id,
        'actual_name' => 'Gram',
        'short_name' => 'g',
        'allow_decimal' => true,
        'base_unit_id' => $kg->id,
        'base_unit_multiplier' => 0.001,
    ]);
    $rivalUnit = Unit::create([
        'business_id' => $otherBusiness->id,
        'actual_name' => 'Hidden Unit',
        'short_name' => 'hu',
        'allow_decimal' => false,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->kgUnit = $kg;
    test()->gUnit = $g;
    test()->rivalUnit = $rivalUnit;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginUnitUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

it('returns only the units belonging to the auth business', function () {
    loginUnitUser();

    $response = $this->getJson('/api/v1/units');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'business_id',
                'actual_name',
                'short_name',
                'allow_decimal',
                'base_unit_id',
                'base_unit_multiplier',
                'created_at',
                'updated_at',
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $ids = collect($items)->pluck('id')->all();
    expect($ids)->toContain(test()->kgUnit->id);
    expect($ids)->toContain(test()->gUnit->id);
    expect($ids)->not->toContain(test()->rivalUnit->id);

    $gram = collect($items)->firstWhere('id', test()->gUnit->id);
    expect($gram['short_name'])->toBe('g');
    expect($gram['base_unit_id'])->toBe(test()->kgUnit->id);
    expect((float) $gram['base_unit_multiplier'])->toBe(0.001);
});

it('returns the full UnitResource shape on show for an own unit', function () {
    loginUnitUser();

    $response = $this->getJson('/api/v1/units/'.test()->kgUnit->id);

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', test()->kgUnit->id);
    $response->assertJsonPath('data.actual_name', 'Kilogram');
    $response->assertJsonPath('data.short_name', 'kg');
    $response->assertJsonPath('data.business_id', test()->business->id);
    $response->assertJsonPath('data.allow_decimal', true);
    $response->assertJsonPath('data.base_unit_id', null);
});

it('returns 404 when the unit id belongs to a different business', function () {
    loginUnitUser();

    $response = $this->getJson('/api/v1/units/'.test()->rivalUnit->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/units');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
});
