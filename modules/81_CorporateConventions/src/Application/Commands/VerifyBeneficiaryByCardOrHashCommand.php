<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Instant lookup of a pass by QR payload, card, matricola hash or e-mail; enrols the holder of a listed card or hash when a name is given.
 */
final readonly class VerifyBeneficiaryByCardOrHashCommand
{
    public function __construct(
        public string $identifier,
        public ?string $conventionId = null,
        public ?string $fullName = null,
        public ?string $email = null,
        public ?string $userId = null,
    ) {}
}
