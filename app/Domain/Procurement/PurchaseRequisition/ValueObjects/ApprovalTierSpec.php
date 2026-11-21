<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\ValueObjects;

/**
 * Single sequential tier specification in a Mod 044 Purchase Requisition approval chain.
 */
final readonly class ApprovalTierSpec
{
    public function __construct(
        public int $level,
        public string $approverType,
        public string $approverValue,
        public string $label,
        public ?string $thresholdMin,
        public ?string $thresholdMax,
        public string $currency,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'approver_type' => $this->approverType,
            'approver_value' => $this->approverValue,
            'label' => $this->label,
            'threshold_min' => $this->thresholdMin,
            'threshold_max' => $this->thresholdMax,
            'currency' => $this->currency,
        ];
    }
}
