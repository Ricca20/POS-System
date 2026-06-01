<?php

use App\Business;
use App\BusinessLocation;
use App\Contact;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/pos/recent-transactions` (Pest, task 5.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Returns own sales for the auth business, ordered by
|      transaction_date DESC.
|   2. Filters by status (`?status=draft` returns drafts; default
|      returns final).
|   3. Respects the `limit` query param.
|   4. Cross-business sales never appear in the list (scoping enforced).
|
| Bootstrap is intentionally minimal: we don't need products/variations
| because this endpoint reads from `transactions` only.
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
        $t->text('additional_notes')->nullable();
        $t->integer('created_by')->unsigned();
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

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->aliceLoc = $aliceLoc;
    test()->rivalLoc = $rivalLoc;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginPosRecentUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

function grantSellViewForRecent(): void
{
    Gate::define('sell.view', fn ($user) => true);
}

/**
 * Insert a sales transaction directly. Returns the new id. The fields
 * mirror what the controller projects through PosSaleResource.
 */
function seedRecentSale(array $overrides): int
{
    $defaults = [
        'business_id' => test()->business->id,
        'location_id' => test()->aliceLoc->id,
        'type' => 'sell',
        'sub_type' => 'pos',
        'status' => 'final',
        'payment_status' => 'paid',
        'transaction_date' => '2024-03-10 09:00:00',
        'contact_id' => null,
        'invoice_no' => 'INV-1',
        'total_before_tax' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'shipping_charges' => 0,
        'final_total' => 100,
        'is_direct_sale' => 0,
        'created_by' => test()->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return (int) DB::table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

it('returns the auth user own final sales ordered by transaction_date desc', function () {
    grantSellViewForRecent();
    loginPosRecentUser();

    $oldId = seedRecentSale([
        'invoice_no' => 'INV-OLD',
        'transaction_date' => '2024-03-10 09:00:00',
    ]);
    $newId = seedRecentSale([
        'invoice_no' => 'INV-NEW',
        'transaction_date' => '2024-04-15 12:00:00',
    ]);
    $midId = seedRecentSale([
        'invoice_no' => 'INV-MID',
        'transaction_date' => '2024-04-01 09:00:00',
    ]);

    $response = $this->getJson('/api/v1/pos/recent-transactions');
    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $rows = $response->json('data');
    expect($rows)->toBeArray();
    expect($rows)->toHaveCount(3);
    expect((int) $rows[0]['id'])->toBe($newId);
    expect((int) $rows[1]['id'])->toBe($midId);
    expect((int) $rows[2]['id'])->toBe($oldId);
});

it('filters by status when status=draft is supplied', function () {
    grantSellViewForRecent();
    loginPosRecentUser();

    $finalId = seedRecentSale(['status' => 'final', 'invoice_no' => 'INV-FINAL']);
    $draftId = seedRecentSale(['status' => 'draft', 'invoice_no' => 'INV-DRAFT']);

    // Default (final).
    $finalResponse = $this->getJson('/api/v1/pos/recent-transactions');
    $finalResponse->assertStatus(200);
    $finalRows = $finalResponse->json('data');
    expect($finalRows)->toHaveCount(1);
    expect((int) $finalRows[0]['id'])->toBe($finalId);

    // Drafts.
    $draftResponse = $this->getJson('/api/v1/pos/recent-transactions?status=draft');
    $draftResponse->assertStatus(200);
    $draftRows = $draftResponse->json('data');
    expect($draftRows)->toHaveCount(1);
    expect((int) $draftRows[0]['id'])->toBe($draftId);
});

it('respects the limit query param', function () {
    grantSellViewForRecent();
    loginPosRecentUser();

    for ($i = 1; $i <= 5; $i++) {
        seedRecentSale([
            'invoice_no' => "INV-{$i}",
            'transaction_date' => "2024-04-1{$i} 09:00:00",
        ]);
    }

    $response = $this->getJson('/api/v1/pos/recent-transactions?limit=2');
    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(2);

    // limit > total still works.
    $response = $this->getJson('/api/v1/pos/recent-transactions?limit=50');
    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(5);
});

it('never returns sales from another business', function () {
    grantSellViewForRecent();
    loginPosRecentUser();

    $ownId = seedRecentSale(['invoice_no' => 'INV-MINE']);
    $foreignId = seedRecentSale([
        'business_id' => test()->otherBusiness->id,
        'location_id' => test()->rivalLoc->id,
        'invoice_no' => 'INV-RIVAL',
    ]);

    $response = $this->getJson('/api/v1/pos/recent-transactions');
    $response->assertStatus(200);

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['id'])->toBe($ownId);
    expect((int) $rows[0]['business_id'])->toBe(test()->business->id);
});
