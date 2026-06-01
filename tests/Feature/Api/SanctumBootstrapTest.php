<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Sanctum bootstrap (Pest)
|--------------------------------------------------------------------------
|
| Validates: R10.1, R10.2, R10.6
|
| Asserts that:
|   1. The `api` guard is wired to Sanctum (R10.6).
|   2. Sanctum's stateful domains include the desktop loopback origins (R10.1).
|   3. Sanctum's middleware (EnsureFrontendRequestsAreStateful) is in the
|      `api` middleware group (R10.2).
|   4. The published `personal_access_tokens` migration creates the table
|      after `migrate` runs.
|
| The migration is run against a fresh SQLite in-memory connection, isolated
| from the application's MySQL connection, so we do not depend on the full
| 300-migration suite (most of which use MySQL-specific syntax).
*/

beforeEach(function () {
    config()->set('database.connections.sanctum_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('sanctum_test');
});

it('uses the sanctum driver for the api guard', function () {
    expect(config('auth.guards.api.driver'))->toBe('sanctum');
    expect(config('auth.guards.api.provider'))->toBe('users');
});

it('declares the desktop loopback origins as stateful', function () {
    $stateful = config('sanctum.stateful');

    expect($stateful)->toBeArray()
        ->and($stateful)->toContain('127.0.0.1:8000')
        ->and($stateful)->toContain('localhost:8000');
});

it('applies EnsureFrontendRequestsAreStateful to the api middleware group', function () {
    $kernel = app(\App\Http\Kernel::class);

    $reflection = new ReflectionClass($kernel);
    $property = $reflection->getProperty('middlewareGroups');
    $property->setAccessible(true);
    $groups = $property->getValue($kernel);

    expect($groups)->toHaveKey('api')
        ->and($groups['api'])->toContain(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);
});

it('creates the personal_access_tokens table after running the published migration', function () {
    $migration = require database_path('migrations/2019_12_14_000001_create_personal_access_tokens_table.php');

    $previousDefault = DB::getDefaultConnection();
    DB::setDefaultConnection('sanctum_test');

    try {
        $migration->up();

        expect(Schema::connection('sanctum_test')->hasTable('personal_access_tokens'))->toBeTrue();

        $columns = Schema::connection('sanctum_test')->getColumnListing('personal_access_tokens');
        expect($columns)
            ->toContain('id')
            ->toContain('tokenable_type')
            ->toContain('tokenable_id')
            ->toContain('name')
            ->toContain('token')
            ->toContain('abilities')
            ->toContain('last_used_at')
            ->toContain('expires_at');
    } finally {
        DB::setDefaultConnection($previousDefault);
    }
});
