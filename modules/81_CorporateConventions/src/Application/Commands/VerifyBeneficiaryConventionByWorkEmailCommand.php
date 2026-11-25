<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Verifies the OTP, creates or reactivates the enrolment and issues its wallet pass id.
 */
final readonly class VerifyBeneficiaryConventionByWorkEmailCommand
{
    public function __construct(
        public string $conventionId,
        public string $email,
        public string $code,
        public string $fullName,
        public ?string $userId = null,
    ) {}
}
