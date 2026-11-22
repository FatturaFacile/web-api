<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use Modules\MrpPlanning\Domain\Enums\ProposalType;

/**
 * An action proposed by the engine. Quantities are decimal strings; pegging lists the demand that causes it.
 */
final class PlannedProposal
{
    /**
     * @param  list<array{type: string, id: string, quantity: string}>  $pegging
     * @param  list<string>  $exceptions
     */
    public function __construct(
        public readonly string $id,
        public readonly ProposalType $type,
        public readonly string $itemId,
        public readonly int $lowLevelCode,
        public string $grossQty,
        public string $projectedOnHandQty,
        public string $netQty,
        public string $requiredQty,
        public string $suggestedQty,
        public CarbonImmutable $needBy,
        public CarbonImmutable $releaseBy,
        public CarbonImmutable $plannedStart,
        public CarbonImmutable $plannedEnd,
        public array $pegging = [],
        public array $exceptions = [],
        public ?string $bomVersionId = null,
        public ?string $workCenterId = null,
        public ?string $supplyReference = null,
    ) {}

    public function addException(string $code): void
    {
        if (! in_array($code, $this->exceptions, true)) {
            $this->exceptions[] = $code;
        }
    }
}
