<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\EnrollmentService;

/**
 * Verifies the OTP, creates or reactivates the enrolment and issues its wallet pass id.
 */
final class VerifyBeneficiaryConventionByWorkEmailHandler
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {}

    public function handle(VerifyBeneficiaryConventionByWorkEmailCommand $command): ConventionBeneficiaryEnrollment
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return $this->enrollments->verifyOtp($convention, $command->email, $command->code, $command->fullName, $command->userId);
    }
}
