<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Actual data of an operation confirmation: minutes and quantities as decimal strings. Labour minutes default to setup
 * plus run minutes.
 */
final readonly class ConfirmOperationDTO
{
    public function __construct(
        public string $setupMinutes = '0',
        public string $runMinutes = '0',
        public ?string $laborMinutes = null,
        public string $goodQuantity = '0',
        public string $scrapQuantity = '0',
        public ?string $scrapReason = null,
        public bool $isFinal = false,
        public ?string $postingDate = null,
        public string $downtimeMinutes = '0',
        public ?string $shopFloorExecutionId = null,
    ) {}
}
