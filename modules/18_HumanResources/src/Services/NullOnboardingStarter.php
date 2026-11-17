<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Modules\HumanResources\Contracts\OnboardingStarterInterface;
use Modules\HumanResources\DTOs\OnboardingStartResultDTO;
use Modules\HumanResources\Enums\OnboardingStatus;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Employee;

/**
 * Default onboarding starter until the onboarding checklists of Mod 071 exist. The existing
 * `HR_ONBOARDING_APPROVAL` workflow is already started by the employee lifecycle service on hire.
 */
final readonly class NullOnboardingStarter implements OnboardingStarterInterface
{
    public function start(Employee $employee, CandidateApplication $application): OnboardingStartResultDTO
    {
        return new OnboardingStartResultDTO(OnboardingStatus::NotAvailable);
    }
}
