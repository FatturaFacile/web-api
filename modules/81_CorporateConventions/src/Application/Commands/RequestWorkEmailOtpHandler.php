<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\ConventionOtpChallenge;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\EnrollmentService;

/**
 * Checks the e-mail domain against the convention, creates a time-limited OTP and e-mails it.
 */
final class RequestWorkEmailOtpHandler
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {}

    public function handle(RequestWorkEmailOtpCommand $command): ConventionOtpChallenge
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($command->conventionId);

        return $this->enrollments->requestOtp($convention, $command->email, $command->locale);
    }
}
