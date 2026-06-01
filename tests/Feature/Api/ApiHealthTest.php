<?php

use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| /api/v1/health and JSON-purity 404 (Pest)
|--------------------------------------------------------------------------
|
| Validates: R2.3, R8.1, R8.2
|
| Asserts that:
|   1. GET /api/v1/health returns 200 with a JSON envelope
|      `{"data":{"status":"ok","timestamp":<ISO8601-Z>}}`. (R2.3, R8.2)
|   2. The Content-Type header begins with `application/json`. (R8.2)
|   3. The `timestamp` value parses as a valid Carbon datetime and matches
|      the ISO8601 Zulu (`...Z`) shape. (R8.2)
|   4. A missing `/api/v1/*` path returns a 404 with the canonical JSON
|      not_found envelope rather than Symfony's HTML page. (R8.1)
*/

it('responds 200 with a JSON envelope on GET /api/v1/health', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);

    expect($response->headers->get('Content-Type'))
        ->toStartWith('application/json');

    $response->assertJsonStructure([
        'data' => [
            'status',
            'timestamp',
        ],
    ]);

    $response->assertJsonPath('data.status', 'ok');
});

it('returns an ISO8601 Zulu timestamp parseable by Carbon', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200);

    $timestamp = $response->json('data.timestamp');

    // Z-suffixed ISO8601 (e.g. 2025-01-15T12:34:56Z), as produced by
    // Carbon::toIso8601ZuluString() at second precision.
    expect($timestamp)
        ->toBeString()
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $parsed = Carbon::parse($timestamp);
    expect($parsed)->toBeInstanceOf(Carbon::class);
});

it('returns a JSON not_found envelope for a missing /api/v1/* path', function () {
    $response = $this->getJson('/api/v1/no-such-path');

    $response->assertStatus(404);

    expect($response->headers->get('Content-Type'))
        ->toStartWith('application/json');

    $response->assertExactJson([
        'code' => 'not_found',
        'message' => 'The requested resource was not found.',
        'errors' => null,
    ]);
});
