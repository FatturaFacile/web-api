<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProcurementSupplier',
    title: 'Procurement Supplier',
    description: 'Enterprise supplier entity with vendor master metadata, financial terms, and approval status',
    required: ['id', 'tenant_id', 'supplier_code', 'company_name', 'status']
)]
class ProcurementOpenApiSchemas
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $tenant_id;

    #[OA\Property(example: 'SUP-0001')]
    public string $supplier_code;

    #[OA\Property(example: 'Acme Industrial Supplies GmbH')]
    public string $company_name;

    #[OA\Property(example: 'IT12345678901', nullable: true)]
    public ?string $vat_number;

    #[OA\Property(example: 'active', enum: ['active', 'suspended', 'blocked'])]
    public string $status;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: 'NET30', nullable: true)]
    public ?string $payment_terms_code;

    #[OA\Property(example: 'DAP', nullable: true)]
    public ?string $incoterms;

    #[OA\Property(example: 50000.00, nullable: true)]
    public ?float $credit_limit;

    #[OA\Property(example: 'A', enum: ['A', 'B', 'C', 'D'], nullable: true)]
    public ?string $rating;

    #[OA\Property(example: true)]
    public bool $is_approved_vendor;
}
