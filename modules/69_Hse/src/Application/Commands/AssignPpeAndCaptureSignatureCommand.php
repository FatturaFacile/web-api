<?php

declare(strict_types=1);

namespace Modules\Hse\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Hands PPE to a worker: checks and decrements the stock of Mod 051, hashes the signature, renders the Verbale di
 * Consegna and files it in the Employee Dossier (Mod 068).
 */
final readonly class AssignPpeAndCaptureSignatureCommand
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $productId,
        public string $warehouseId,
        public ?string $lotOrSerialId,
        public float $quantity,
        public ?string $ceMarkingStandard,
        public CarbonImmutable $handedOverAt,
        public ?CarbonImmutable $replacementDueAt,
        public string $signaturePayload,
        public ?string $signatureImage,
        public string $locale,
        public ?string $userId,
    ) {}
}
