<?php

use App\Business;
use App\Contact;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/pos/reward-details` (Pest, task 5.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Valid contact returns the full RP envelope with computed
|      `available_rp` and `redeemable_amount`.
|   2. Cross-business `contact_id` produces a 422 with `errors.contact_id`
|      (the validator's `Rule::exists` is scoped to the auth business).
|   3. Missing `contact_id` produces a 422 with `errors.contact_id`.
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

    // The reward-details endpoint reads the RP business config from the
    // `business` row; the schema below adds every column the controller
    // projects.
    Schema::create('business', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('currency_id')->unsigned();
        $t->string('time_zone')->default('UTC');
        $t->boolean('is_active')->default(true);
        $t->boolean('enable_rp')->default(false);
        $t->string('rp_name')->nullable();
        $t->decimal('amount_for_unit_rp', 22, 4)->default(1);
        $t->decimal('min_order_total_for_rp', 22, 4)->default(1);
        $t->integer('max_rp_per_order')->nullable();
        $t->decimal('redeem_amount_per_unit_rp', 22, 4)->default(1);
        $t->decimal('min_order_total_for_redeem', 22, 4)->default(1);
        $t->integer('min_redeem_point')->nullable();
        $t->integer('max_redeem_point')->nullable();
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

    Schema::create('contacts', function ($t) {
        $t->increments('id');
        $t->integer('business_id')->unsigned();
        $t->string('type')->default('customer');
        $t->boolean('is_default')->default(false);
        $t->string('name')->nullable();
        $t->string('supplier_business_name')->nullable();
        $t->string('contact_status')->default('active');
        // Reward-points columns mirror the production schema.
        $t->integer('total_rp')->default(0);
        $t->integer('total_rp_used')->default(0);
        $t->integer('total_rp_expired')->default(0);
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
        'enable_rp' => true,
        'rp_name' => 'Stars',
        'amount_for_unit_rp' => 10,
        'min_order_total_for_rp' => 5,
        'max_rp_per_order' => 100,
        'redeem_amount_per_unit_rp' => 0.5,
        'min_order_total_for_redeem' => 1,
        'min_redeem_point' => 5,
        'max_redeem_point' => 500,
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

    $contact = Contact::create([
        'business_id' => $business->id,
        'type' => 'customer',
        'is_default' => false,
        'name' => 'Loyal Customer',
        'contact_status' => 'active',
        'total_rp' => 200,
        'total_rp_used' => 50,
        'total_rp_expired' => 30,
    ]);

    // A contact in the rival business — used for the cross-business
    // assertion.
    $foreignContact = Contact::create([
        'business_id' => $otherBusiness->id,
        'type' => 'customer',
        'is_default' => false,
        'name' => 'Rival Customer',
        'contact_status' => 'active',
        'total_rp' => 999,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->contact = $contact;
    test()->foreignContact = $foreignContact;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginPosRewardUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantSellCreateForReward(): void
{
    Gate::define('sell.create', fn ($user) => true);
}

it('returns the full reward envelope for a valid contact', function () {
    grantSellCreateForReward();
    loginPosRewardUser();

    $contactId = test()->contact->id;

    $response = $this->getJson("/api/v1/pos/reward-details?contact_id={$contactId}");
    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'contact_id',
            'total_rp',
            'total_rp_used',
            'total_rp_expired',
            'available_rp',
            'redeemable_amount',
            'rp_name',
            'rp_settings' => [
                'amount_for_unit_rp',
                'min_order_total_for_rp',
                'max_rp_per_order',
                'redeem_amount_per_unit_rp',
                'min_order_total_for_redeem',
                'min_redeem_point',
                'max_redeem_point',
            ],
        ],
    ]);

    expect($response->json('data.contact_id'))->toBe($contactId);
    expect($response->json('data.total_rp'))->toEqual(200.0);
    expect($response->json('data.total_rp_used'))->toEqual(50.0);
    expect($response->json('data.total_rp_expired'))->toEqual(30.0);
    // available_rp = 200 - 50 - 30 = 120.
    expect($response->json('data.available_rp'))->toEqual(120.0);
    // redeemable_amount = 120 * 0.5 = 60.
    expect($response->json('data.redeemable_amount'))->toEqual(60.0);
    expect($response->json('data.rp_name'))->toBe('Stars');
    expect($response->json('data.rp_settings.amount_for_unit_rp'))->toEqual(10.0);
    expect($response->json('data.rp_settings.redeem_amount_per_unit_rp'))->toEqual(0.5);
    expect($response->json('data.rp_settings.min_redeem_point'))->toBe(5);
    expect($response->json('data.rp_settings.max_redeem_point'))->toBe(500);
});

it('rejects a contact_id from another business with 422', function () {
    grantSellCreateForReward();
    loginPosRewardUser();

    $foreignId = test()->foreignContact->id;

    $response = $this->getJson("/api/v1/pos/reward-details?contact_id={$foreignId}");
    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['contact_id'],
    ]);
});

it('rejects a missing contact_id with 422', function () {
    grantSellCreateForReward();
    loginPosRewardUser();

    $response = $this->getJson('/api/v1/pos/reward-details');
    $response->assertStatus(422);
    $response->assertJsonPath('code', 'validation_failed');
    $response->assertJsonStructure([
        'code',
        'message',
        'errors' => ['contact_id'],
    ]);
});
