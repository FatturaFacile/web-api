<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;

/**
 * Sends the stage email of a candidate and logs every attempt. Stands in for the email module of Mod 008.
 */
interface CandidateEmailSenderInterface
{
    public function sendStageEmail(CandidateApplication $application, ApplicationStage $stage): CandidateEmail;
}
