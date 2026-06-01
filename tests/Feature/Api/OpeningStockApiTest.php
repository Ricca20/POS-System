<?php

use App\Business;
use App\BusinessLocation;
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
| `POST /api/v1/stock/opening` (Pest, task 4.4)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. First-time set creates a `variation_location_details` row with the
|      supplied `qty`.
|   2. Second call updates the existing row (overwrites, does not add).
|   3. `qty = 0` is permitted (legitimate initial-stock-zero case).
|
| Bootstrap mirrors the other stock-related tests but only includes the
| tables this endpoint actually touches.
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

    $location = BusinessLocation::create([
        'business_id' => $business->id,
        'name' => 'Acme HQ',
    ]);

    $product = Product::create([
        'business_id' => $business->id,
        'name' => 'Widget',
        'type' => 'single',
        'sku' => 'W-1',
        'unit_id' => 1,
        'enable_stock' => true,
        'not_for_selling' => false,
        'created_by' => $user->id,
    ]);
    $variation = Variation::create([
        'name' => 'DUMMY',
        'product_id' => $product->id,
        'sub_sku' => 'W-1-V',
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->location = $location;
    test()->product = $product;
    test()->variation = $variation;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginOpeningStockUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantOpeningStockPermissions(): void
{
    Gate::define('product.opening_stock', fn ($user) => true);
}

it('creates a new variation_location_details row when one does not exist', function () {
    grantOpeningStockPermissions();
    loginOpeningStockUser();

    expect(DB::table('variation_location_details')->count())->toBe(0);

    $response = $this->postJson('/api/v1/stock/opening', [
        'product_id' => test()->product->id,
        'variation_id' => test()->variation->id,
        'location_id' => test()->location->id,
        'qty' => 25,
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.product_id', test()->product->id);
    $response->assertJsonPath('data.variation_id', test()->variation->id);
    $response->assertJsonPath('data.location_id', test()->location->id);
    expect((float) $response->json('data.qty_available'))->toEqual(25.0);

    $row = DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->location->id)
        ->first();
    expect($row)->not->toBeNull();
    expect((float) $row->qty_available)->toEqual(25.0);
    expect(DB::table('variation_location_details')->count())->toBe(1);
});

it('overwrites (not adds) qty_available on a second call to opening stock', function () {
    grantOpeningStockPermissions();
    loginOpeningStockUser();

    // Pre-existing row at 100. Opening stock semantics dictate the row
    // is set to the new value, not incremented by it.
    VariationLocationDetails::create([
        'product_id' => test()->product->id,
        'variation_id' => test()->variation->id,
        'location_id' => test()->location->id,
        'qty_available' => 100,
    ]);

    $response = $this->postJson('/api/v1/stock/opening', [
        'product_id' => test()->product->id,
        'variation_id' => test()->variation->id,
        'location_id' => test()->location->id,
        'qty' => 40,
    ]);

    $response->assertStatus(200);

    $row = DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->location->id)
        ->first();

    // Set to 40, NOT 100 + 40 = 140.
    expect((float) $row->qty_available)->toEqual(40.0);
    expect(DB::table('variation_location_details')->count())->toBe(1);
});

it('permits qty=0 as a legitimate initial-stock-zero state', function () {
    grantOpeningStockPermissions();
    loginOpeningStockUser();

    $response = $this->postJson('/api/v1/stock/opening', [
        'product_id' => test()->product->id,
        'variation_id' => test()->variation->id,
        'location_id' => test()->location->id,
        'qty' => 0,
    ]);

    $response->assertStatus(200);
    expect((float) $response->json('data.qty_available'))->toEqual(0.0);

    $row = DB::table('variation_location_details')
        ->where('variation_id', test()->variation->id)
        ->where('location_id', test()->location->id)
        ->first();
    expect($row)->not->toBeNull();
    expect((float) $row->qty_available)->toEqual(0.0);
});
