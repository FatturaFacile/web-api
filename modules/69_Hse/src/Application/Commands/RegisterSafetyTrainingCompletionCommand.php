<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\Hse\Domain\Enums\CertificateType;

final readonly class RegisterSafetyTrainingCompletionCommand
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public CertificateType $certificateType,
        public ?string $certificateNumber,
        public ?string $trainingProviderName,
        public float $hoursCompleted,
        public CarbonImmutable $issuedAt,
        public ?CarbonImmutable $expiresAt,
        public bool $specificRiskClass,
        public ?string $certificatePdfBase64,
        public ?string $userId,
    ) {}
}
