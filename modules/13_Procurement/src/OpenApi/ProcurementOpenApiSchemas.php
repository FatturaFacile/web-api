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

#[OA\Schema(
    schema: 'ProcurementApiPaginationMeta',
    title: 'API Pagination Meta',
    description: 'Standard pagination metadata',
    required: ['current_page', 'per_page', 'total']
)]
class ProcurementApiPaginationMetaSchema
{
    #[OA\Property(example: 1)]
    public int $current_page;

    #[OA\Property(example: 25)]
    public int $per_page;

    #[OA\Property(example: 150)]
    public int $total;

    #[OA\Property(example: 6)]
    public int $last_page;
}

#[OA\Schema(
    schema: 'ProcurementApiErrorResponse',
    title: 'Procurement API Error Response',
    description: 'Standard error envelope for procurement endpoints',
    required: ['success', 'message']
)]
class ProcurementApiErrorResponseSchema
{
    #[OA\Property(example: false)]
    public bool $success;

    #[OA\Property(example: 'Resource not found or unauthorized')]
    public string $message;

    #[OA\Property(example: 401)]
    public int $status;
}

#[OA\Schema(
    schema: 'ProcurementSupplierScorecard',
    title: 'Supplier Scorecard',
    description: 'Quarterly supplier performance metrics, OTIF percentage, and AVL status',
    required: ['id', 'supplier_id', 'year', 'quarter', 'otif_percentage', 'risk_level']
)]
class ProcurementSupplierScorecardSchema
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $supplier_id;

    #[OA\Property(example: 2026)]
    public int $year;

    #[OA\Property(example: 3)]
    public int $quarter;

    #[OA\Property(example: 98.5)]
    public float $otif_percentage;

    #[OA\Property(example: 25, nullable: true)]
    public ?int $defect_ppm;

    #[OA\Property(example: 12500.00)]
    public float $cost_savings_amount;

    #[OA\Property(example: 95.0)]
    public float $compliance_score;

    #[OA\Property(example: 'low', enum: ['low', 'medium', 'high', 'critical'])]
    public string $risk_level;

    #[OA\Property(example: true)]
    public bool $is_avl_active;

    #[OA\Property(type: 'object', nullable: true)]
    public ?array $scorecard_data;
}

#[OA\Schema(
    schema: 'ProcurementApiValidationErrorResponse',
    title: 'Procurement API Validation Error Response',
    description: 'Validation errors dictionary',
    required: ['message', 'errors']
)]
class ProcurementApiValidationErrorResponseSchema
{
    #[OA\Property(example: 'The given data was invalid.')]
    public string $message;

    #[OA\Property(type: 'object')]
    public array $errors;
}
