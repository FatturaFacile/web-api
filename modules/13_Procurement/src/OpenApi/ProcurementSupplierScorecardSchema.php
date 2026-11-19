<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

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
