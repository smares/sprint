<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Number;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The number locale is static and would otherwise outlive the request of an earlier test.
        Number::useLocale('en');

        // The test client would otherwise ask for English; the suite checks the German interface unless a test says otherwise.
        $this->withHeader('Accept-Language', 'de');
    }
}
