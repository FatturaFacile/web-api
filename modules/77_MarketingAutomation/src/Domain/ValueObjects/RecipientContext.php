<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\ValueObjects;

final readonly class RecipientContext
{
    public function __construct(
        public string $jurisdiction,
        public string $locale,
        public bool $isExistingCustomer,
        public bool $boughtSimilarProducts,
        public bool $senderIdentified = true,
        public bool $addressSuppressed = false,
    ) {}
}
