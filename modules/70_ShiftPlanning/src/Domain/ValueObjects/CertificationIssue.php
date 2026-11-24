<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A mandatory safety or HSE certificate that is expired (or has no expiry date on record) on the shift date (Mod 099).
 */
final readonly class CertificationIssue
{
    public function __construct(
        public string $code,
        public string $name,
        public ?CarbonImmutable $expiredOn,
    ) {}
}
