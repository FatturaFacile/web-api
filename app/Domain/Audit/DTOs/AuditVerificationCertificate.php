<?php

declare(strict_types=1);

namespace App\Domain\Audit\DTOs;

/**
 * Forensic result of a chain verification. The signature is an HMAC over the other fields, so a certificate printed
 * for an auditor can be checked later by the platform.
 */
final readonly class AuditVerificationCertificate
{
    public function __construct(
        public bool $isValid,
        public int $checkedRecords,
        public ?int $firstSequence,
        public ?int $lastSequence,
        public ?string $merkleRoot,
        public ?int $brokenAtSequence,
        public ?string $failureReason,
        public string $verifiedAt,
        public ?string $tenantId,
        public string $signature = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'isValid' => $this->isValid,
            'checkedRecords' => $this->checkedRecords,
            'firstSequence' => $this->firstSequence,
            'lastSequence' => $this->lastSequence,
            'merkleRoot' => $this->merkleRoot,
            'brokenAtSequence' => $this->brokenAtSequence,
            'failureReason' => $this->failureReason,
            'verifiedAt' => $this->verifiedAt,
            'tenantId' => $this->tenantId,
        ];
    }

    public function withSignature(string $signature): self
    {
        return new self(
            $this->isValid, $this->checkedRecords, $this->firstSequence, $this->lastSequence, $this->merkleRoot,
            $this->brokenAtSequence, $this->failureReason, $this->verifiedAt, $this->tenantId, $signature,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload() + ['signature' => $this->signature];
    }
}
