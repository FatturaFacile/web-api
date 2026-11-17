<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\DTOs;

final readonly class RegisterEquipmentPassportDTO
{
    /**
     * @param  list<array{position: string, component_serial: string, component_product_id?: string|null, role?: string|null}>  $components
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $serialNumber,
        public string $currentOwnerCustomerId,
        public string $deliveryDate,
        public ?string $stockSerialNumberId = null,
        public ?string $productId = null,
        public ?string $originalSalesInvoiceId = null,
        public ?string $customerType = null,
        public ?string $jurisdiction = null,
        public ?int $contractWarrantyMonths = null,
        public ?string $contractClauseReference = null,
        public ?string $installedFirmwareVersion = null,
        public ?string $notes = null,
        public array $components = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromValidated(string $tenantId, ?string $companyId, array $data): self
    {
        return new self(
            tenantId: $tenantId,
            companyId: $companyId,
            serialNumber: (string) $data['serial_number'],
            currentOwnerCustomerId: (string) $data['current_owner_customer_id'],
            deliveryDate: (string) $data['delivery_date'],
            stockSerialNumberId: $data['stock_serial_number_id'] ?? null,
            productId: $data['product_id'] ?? null,
            originalSalesInvoiceId: $data['original_sales_invoice_id'] ?? null,
            customerType: $data['customer_type'] ?? null,
            jurisdiction: isset($data['jurisdiction']) ? strtoupper((string) $data['jurisdiction']) : null,
            contractWarrantyMonths: isset($data['contract_warranty_months']) ? (int) $data['contract_warranty_months'] : null,
            contractClauseReference: $data['contract_clause_reference'] ?? null,
            installedFirmwareVersion: $data['installed_firmware_version'] ?? null,
            notes: $data['notes'] ?? null,
            components: array_values($data['components'] ?? []),
        );
    }
}
