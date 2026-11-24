<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

/**
 * Parameters of an MRP run: horizon, run type, optional items and the user who ran it.
 */
final readonly class RunMrpDTO
{
    /**
     * @param  array<int, string>|null  $itemIds
     * @param  array<string, mixed>|null  $parameters
     */
    public function __construct(
        public string $tenantId,
        public string $horizonStartDate,
        public string $horizonEndDate,
        public string $runType = 'regenerative',
        public ?array $itemIds = null,
        public ?string $executedBy = null,
        public ?array $parameters = null
    ) {}
}
