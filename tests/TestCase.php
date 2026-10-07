<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test client would otherwise ask for English; the suite checks the German interface unless a test says otherwise.
        $this->withHeader('Accept-Language', 'de');
    }
}
