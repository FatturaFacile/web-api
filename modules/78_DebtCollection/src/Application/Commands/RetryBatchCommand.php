<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Application\Commands;

final readonly class RetryBatchCommand
{
    public function __construct(
        public string $tenantId,
        public string $batchId,
        public ?string $actorId,
    ) {}
}
