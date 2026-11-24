<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use App\Models\User;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Models\WorkflowInstanceStep;

interface ExternalActionTokenServiceInterface
{
    /**
     * Generates a single-use action token and returns the signed URL.
     */
    public function generateActionToken(WorkflowInstanceStep $step, User $user, string $actionType): string;

    /**
     * Verifies and consumes a raw action token secret.
     */
    public function verifyAndConsumeToken(string $rawToken, string $actionType): ApprovalToken;
}
