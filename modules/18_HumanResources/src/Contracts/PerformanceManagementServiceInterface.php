<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\SubmitPerformanceReviewDTO;
use Modules\HumanResources\Models\PerformanceReview;

interface PerformanceManagementServiceInterface
{
    public function submitReview(SubmitPerformanceReviewDTO $dto, ?string $actorUserId = null): PerformanceReview;

    public function completeReview(PerformanceReview $review, ?string $actorUserId = null): PerformanceReview;
}
