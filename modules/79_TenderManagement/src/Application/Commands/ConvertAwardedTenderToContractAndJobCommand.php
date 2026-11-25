<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Converts an awarded dossier into the contract (Mod 108) and the project (Mod 065) in one transaction.
 */
final readonly class ConvertAwardedTenderToContractAndJobCommand
{
    public function __construct(
        public string $participationId,
        public ?string $userId,
        public ?string $contactId = null,
        public ?string $projectManagerUserId = null,
        public ?string $plannedStartDate = null,
        public ?string $plannedEndDate = null,
        public ?string $contractTitle = null,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
