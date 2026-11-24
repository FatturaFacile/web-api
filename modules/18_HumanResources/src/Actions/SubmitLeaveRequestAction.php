<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Models\LeaveRequest;

final readonly class SubmitLeaveRequestAction
{
    public function __construct(
        private LeaveAndTimeServiceInterface $leaveService
    ) {}

    public function execute(SubmitLeaveRequestDTO $dto, ?string $actorUserId = null): LeaveRequest
    {
        return $this->leaveService->submitLeaveRequest($dto, $actorUserId);
    }
}
