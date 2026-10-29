<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class SubmitPerformanceReviewDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $goals
     * @param  array<int, array<string, mixed>>  $competencies
     */
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $reviewPeriod,
        public float $goalsScore,
        public float $competenciesScore,
        public ?string $reviewerEmployeeId = null,
        public string $reviewType = 'annual',
        public bool $promotionRecommended = false,
        public ?string $recommendedJobGradeId = null,
        public float $recommendedSalaryIncreasePercent = 0.0,
        public ?string $successorReadiness = null,
        public array $goals = [],
        public array $competencies = [],
        public ?string $strengths = null,
        public ?string $improvementAreas = null,
        public ?string $careerPathNotes = null
    ) {}
}
