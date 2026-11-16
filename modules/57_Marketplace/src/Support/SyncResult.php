<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

/**
 * The outcome of one synchronisation of a resource.
 */
final readonly class SyncResult
{
    public function __construct(public int $processed = 0, public int $failed = 0, public ?string $message = null) {}
}
