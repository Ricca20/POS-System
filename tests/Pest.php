<?php

/*
|--------------------------------------------------------------------------
| Pest Test Configuration
|--------------------------------------------------------------------------
|
| Binds Pest's `Feature` and `Unit` test suites to the application's base
| TestCase so Pest tests get a booted Laravel kernel. PHPUnit-style tests
| under tests/Unit and tests/Feature continue to work unchanged.
|
*/

uses(Tests\TestCase::class)->in('Feature');
uses(Tests\TestCase::class)->in('Unit');
