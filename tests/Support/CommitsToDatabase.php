<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Keeps RefreshDatabase from wrapping the test in a transaction, for tests that need other connections to see their
 * rows. Such a test must delete what it wrote.
 */
trait CommitsToDatabase
{
    /** @var list<string> */
    protected array $connectionsToTransact = [];
}
