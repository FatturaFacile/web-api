<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\OnboardingStartResultDTO;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\Employee;

/**
 * Starts the onboarding checklist of a new hire. Stands in for Mod 071; runs inside the hire transaction.
 */
interface OnboardingStarterInterface
{
    public function start(Employee $employee, CandidateApplication $application): OnboardingStartResultDTO;
}
