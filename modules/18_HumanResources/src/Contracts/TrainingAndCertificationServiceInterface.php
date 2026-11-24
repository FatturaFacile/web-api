<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Modules\HumanResources\DTOs\AssignTrainingDTO;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\TrainingRecord;

interface TrainingAndCertificationServiceInterface
{
    public function assignTraining(AssignTrainingDTO $dto, ?string $actorUserId = null): TrainingRecord;

    public function completeTraining(TrainingRecord $trainingRecord, float $score = 100.0, ?string $actorUserId = null): TrainingRecord;

    public function scanExpiringCertifications(string $tenantId, int $withinDays = 30): int;

    public function issueCertification(string $tenantId, string $employeeId, string $code, string $name, string $expiryDate, ?string $courseId = null): Certification;
}
