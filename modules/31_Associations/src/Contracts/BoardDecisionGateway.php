<?php

declare(strict_types=1);

namespace Modules\Associations\Contracts;

use Modules\Associations\DTOs\BoardDecisionRequest;
use Modules\Associations\Models\EnrolmentApplication;

/**
 * How an application that needs the board reaches it. The governance module implements this to put the application on
 * the agenda of a meeting; when the board has decided it calls EnrolmentService::applyBoardDecision.
 */
interface BoardDecisionGateway
{
    public function request(EnrolmentApplication $application): BoardDecisionRequest;
}
