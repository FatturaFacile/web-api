<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Domain\ValueObjects;

final readonly class PrivacyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $lawfulBasis,
        public ?string $reasonCode,
    ) {}

    public static function allow(string $lawfulBasis): self
    {
        return new self(true, $lawfulBasis, null);
    }

    public static function deny(string $reasonCode): self
    {
        return new self(false, null, $reasonCode);
    }
}
