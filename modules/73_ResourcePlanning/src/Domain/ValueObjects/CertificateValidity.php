<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Whether the safety certificate a skill requires (Mod 099) covers a whole period. $status is valid, missing or expired.
 */
final readonly class CertificateValidity
{
    public const string Valid = 'valid';

    public const string Missing = 'missing';

    public const string Expired = 'expired';

    public function __construct(public string $status, public ?CarbonImmutable $validUntil = null) {}

    public function isValid(): bool
    {
        return $this->status === self::Valid;
    }
}
