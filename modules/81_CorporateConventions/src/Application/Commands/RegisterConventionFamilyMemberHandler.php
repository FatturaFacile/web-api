<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Services\EnrollmentService;

/**
 * Adds a first-degree family member up to the limit of the convention and issues a linked wallet pass.
 */
final class RegisterConventionFamilyMemberHandler
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {}

    public function handle(RegisterConventionFamilyMemberCommand $command): ConventionBeneficiaryEnrollment
    {
        $parent = ConventionBeneficiaryEnrollment::query()->findOrFail($command->enrollmentId);

        return $this->enrollments->registerFamilyMember($parent, $command->fullName, $command->relationship, $command->userId);
    }
}
