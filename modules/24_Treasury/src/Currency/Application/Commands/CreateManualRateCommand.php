<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

use Modules\Treasury\Currency\Domain\Enums\RateType;

/**
 * A rate entered by hand for a company: a manual rate or a rate fixed for a contract, an order or a document (a reason is
 * mandatory for a contract rate).
 */
final readonly class CreateManualRateCommand
{
    public function __construct(
        public string $tenantId,
        public string $base,
        public string $quote,
        public string $rate,
        public string $validOn,
        public RateType $type = RateType::Mid,
        public ?string $reason = null,
        public ?string $contractReference = null,
        public ?string $actorId = null,
    ) {}
}
