<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

/**
 * Decision on a PPE garment at its certified wash limit: replace it, send it to mending, condemn it as a rag, or (action
 * "complete_mending") put a mended garment back in stock.
 */
final readonly class ResolvePpeWashLimitAlertCommand
{
    public function __construct(
        public string $garmentId,
        public string $action,
        public ?string $replacementEpc,
        public ?string $reason,
        public ?string $userId,
    ) {}
}
