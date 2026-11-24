<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

/**
 * CQRS command to calculate or recalculate a Swiss VAT period return.
 */
final readonly class CalculateSwissVatReturnCommand
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public string $tenantId,
        public ?string $actorUserId,
        public int $fiscalYear,
        public string $periodType,
        public int $periodNumber,
        public array $overrides = [],
    ) {}
}
