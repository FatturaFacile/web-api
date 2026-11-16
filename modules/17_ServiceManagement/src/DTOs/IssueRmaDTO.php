<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class IssueRmaDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $reportedSymptom,
        public ?string $serialNumber = null,
        public ?string $equipmentPassportId = null,
        public ?string $productId = null,
        public ?string $preauthorizedThreshold = null,
        public ?string $ddtCausaleOutbound = null,
        public bool $goodwill = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(string $tenantId, ?string $companyId, array $data): self
    {
        return new self(
            tenantId: $tenantId,
            companyId: $companyId,
            reportedSymptom: (string) $data['reported_symptom'],
            serialNumber: $data['serial_number'] ?? null,
            equipmentPassportId: $data['equipment_passport_id'] ?? null,
            productId: $data['product_id'] ?? null,
            preauthorizedThreshold: isset($data['preauthorized_threshold']) ? number_format((float) $data['preauthorized_threshold'], 2, '.', '') : null,
            ddtCausaleOutbound: $data['ddt_causale_outbound'] ?? null,
            goodwill: (bool) ($data['goodwill'] ?? false),
        );
    }
}
