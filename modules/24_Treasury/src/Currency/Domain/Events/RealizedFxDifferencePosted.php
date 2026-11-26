<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

/**
 * The realised exchange difference of a settlement was recorded (and posted to the ledger when the settlement had not posted it).
 */
final readonly class RealizedFxDifferencePosted
{
    public function __construct(
        public string $tenantId,
        public string $differenceId,
        public string $allocationId,
        public string $currency,
        public string $effect,
        public string $result,
        public ?string $journalEntryId,
    ) {}
}
