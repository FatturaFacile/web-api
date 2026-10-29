<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Contracts;

use Modules\ServiceManagement\DTOs\CreateChangeRequestDTO;
use Modules\ServiceManagement\DTOs\CreateProblemDTO;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\Problem;

interface ProblemAndChangeServiceInterface
{
    public function createProblem(CreateProblemDTO $dto, ?string $actorUserId = null): Problem;

    public function resolveProblem(string $problemId, ?string $workaround = null, ?string $permanentSolution = null, ?string $actorUserId = null): Problem;

    public function createChangeRequest(CreateChangeRequestDTO $dto, ?string $actorUserId = null): ChangeRequest;

    public function approveChangeRequest(string $changeRequestId, string $approvedBy, ?string $actorUserId = null): ChangeRequest;

    public function implementChangeRequest(string $changeRequestId, ?string $actorUserId = null): ChangeRequest;
}
