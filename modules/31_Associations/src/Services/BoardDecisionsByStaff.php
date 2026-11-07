<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\DTOs\BoardDecisionRequest;
use Modules\Associations\Models\EnrolmentApplication;

/**
 * The default until a governance module exists: the application waits in the list, and the staff records the decision
 * of the board through the API.
 */
final class BoardDecisionsByStaff implements BoardDecisionGateway
{
    public function request(EnrolmentApplication $application): BoardDecisionRequest
    {
        return new BoardDecisionRequest;
    }
}
