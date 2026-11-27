<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

/**
 * Checks the credentials and the reachability of the configured gateway.
 */
final readonly class TestEbillConnectionCommand
{
    public function __construct(public ?string $userId = null) {}
}
