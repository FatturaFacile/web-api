<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Models\ProjectChangeOrder;

/**
 * Change orders of projects: registration, approval (applied once) and rejection.
 */
interface ProjectChangeOrderServiceInterface
{
    public function createChangeOrder(ChangeOrderDTO $dto): ProjectChangeOrder;

    public function approveChangeOrder(ProjectChangeOrder $changeOrder, string $approvedBy): ProjectChangeOrder;

    public function rejectChangeOrder(ProjectChangeOrder $changeOrder, string $rejectedBy, ?string $reason = null): ProjectChangeOrder;
}
