<?php

use App\Business;
use App\User;
use App\VariationTemplate;
use App\VariationValueTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| `GET /api/v1/variation-templates` and `/api/v1/variation-templates/{id}`
| (Pest, task 4.3)
|--------------------------------------------------------------------------
|
| Validates: R8.1, R8.2.
|
| Asserts:
|   1. Authenticated GET on the index returns 200 + only the
|      authenticated business's variation templates with embedded
|      `variation_value_templates`.
|   2. Authenticated GET on a valid id returns 200 with the full
|      VariationTemplateResource shape (including embedded values).
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

    // The legacy schema does not soft-delete variation templates, so a
    // tight `id, name, business_id, timestamps` table is sufficient.
    Schema::create('variation_templates', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('business_id')->unsigned();
        $t->timestamps();
    });

    // Child table — the resource embeds these via the `values` relation.
    Schema::create('variation_value_templates', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->integer('variation_template_id')->unsigned();
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

    $sizeTemplate = VariationTemplate::create([
        'business_id' => $business->id,
        'name' => 'T-Shirt Sizes',
    ]);
    VariationValueTemplate::create([
        'name' => 'S',
        'variation_template_id' => $sizeTemplate->id,
    ]);
    VariationValueTemplate::create([
        'name' => 'M',
        'variation_template_id' => $sizeTemplate->id,
    ]);
    VariationValueTemplate::create([
        'name' => 'L',
        'variation_template_id' => $sizeTemplate->id,
    ]);

    $colorTemplate = VariationTemplate::create([
        'business_id' => $business->id,
        'name' => 'Colors',
    ]);
    VariationValueTemplate::create([
        'name' => 'Red',
        'variation_template_id' => $colorTemplate->id,
    ]);
    VariationValueTemplate::create([
        'name' => 'Blue',
        'variation_template_id' => $colorTemplate->id,
    ]);

    $rivalTemplate = VariationTemplate::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Rival Hidden',
    ]);
    VariationValueTemplate::create([
        'name' => 'Hidden',
        'variation_template_id' => $rivalTemplate->id,
    ]);

    test()->user = $user;
    test()->business = $business;
    test()->otherBusiness = $otherBusiness;
    test()->sizeTemplate = $sizeTemplate;
    test()->colorTemplate = $colorTemplate;
    test()->rivalTemplate = $rivalTemplate;

    test()->withHeaders([
        'Origin' => 'http://127.0.0.1:8000',
        'Referer' => 'http://127.0.0.1:8000/',
    ]);
});

function loginVariationTemplateUser(): void
{
    test()->postJson('/api/v1/auth/login', [
        'login' => 'alice',
        'password' => 'correct-horse-battery',
        'device_name' => 'Pest Suite',
    ])->assertStatus(200);
}

it('returns only the variation templates belonging to the auth business with embedded values', function () {
    loginVariationTemplateUser();

    $response = $this->getJson('/api/v1/variation-templates');

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'business_id',
                'name',
                'variation_value_templates' => [
                    '*' => [
                        'id',
                        'name',
                        'variation_template_id',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'created_at',
                'updated_at',
            ],
        ],
    ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(2);

    $ids = collect($items)->pluck('id')->all();
    expect($ids)->toContain(test()->sizeTemplate->id);
    expect($ids)->toContain(test()->colorTemplate->id);
    expect($ids)->not->toContain(test()->rivalTemplate->id);

    $sizes = collect($items)->firstWhere('id', test()->sizeTemplate->id);
    expect($sizes['name'])->toBe('T-Shirt Sizes');
    expect($sizes['variation_value_templates'])->toHaveCount(3);

    $valueNames = collect($sizes['variation_value_templates'])->pluck('name')->all();
    expect($valueNames)->toContain('S');
    expect($valueNames)->toContain('M');
    expect($valueNames)->toContain('L');
});

it('returns the full VariationTemplateResource shape on show for an own template', function () {
    loginVariationTemplateUser();

    $response = $this->getJson('/api/v1/variation-templates/'.test()->colorTemplate->id);

    $response->assertStatus(200);
    $response->assertJsonPath('data.id', test()->colorTemplate->id);
    $response->assertJsonPath('data.name', 'Colors');
    $response->assertJsonPath('data.business_id', test()->business->id);

    $values = $response->json('data.variation_value_templates');
    expect($values)->toHaveCount(2);

    $valueNames = collect($values)->pluck('name')->all();
    expect($valueNames)->toContain('Red');
    expect($valueNames)->toContain('Blue');
});

it('returns 404 when the variation template id belongs to a different business', function () {
    loginVariationTemplateUser();

    $response = $this->getJson('/api/v1/variation-templates/'.test()->rivalTemplate->id);

    $response->assertStatus(404);
    $response->assertJsonPath('code', 'not_found');
});

it('rejects unauthenticated GET requests with the canonical 401 envelope', function () {
    $response = $this->getJson('/api/v1/variation-templates');

    $response->assertStatus(401);
    $response->assertJsonPath('code', 'unauthenticated');
});
