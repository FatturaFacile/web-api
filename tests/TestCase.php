<?php

namespace Tests;

use App\Contracts\Security\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\StaticHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Outbound URLs are resolved before every request: tests never reach the real DNS.
        $this->app->instance(HostResolver::class, new StaticHostResolver);
    }
}
