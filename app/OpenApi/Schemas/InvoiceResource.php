<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InvoiceResource',
    description: 'Sales invoice details',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'),
        new OA\Property(property: 'invoice_number', type: 'string', example: 'INV-2026-0042'),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', example: '2026-10-25'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', example: '2026-11-24'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'issued', 'paid', 'cancelled'], example: 'issued'),
        new OA\Property(property: 'subtotal', type: 'number', format: 'float', example: 1250.00),
        new OA\Property(property: 'tax_amount', type: 'number', format: 'float', example: 101.25),
        new OA\Property(property: 'total', type: 'number', format: 'float', example: 1351.25),
        new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
        new OA\Property(property: 'has_swiss_qr', type: 'boolean', example: true),
        new OA\Property(property: 'vat_payability', type: 'string', enum: ['I', 'D', 'S'], example: 'I'),
        new OA\Property(property: 'total_due', type: 'string', nullable: true, description: 'ImportoPagamento: total minus withholding, ENASARCO and split-payment VAT (decimal string)', example: '1068.80'),
        new OA\Property(property: 'fund', type: 'object', nullable: true, example: ['type' => 'TC22', 'percent' => '4.00', 'amount' => '40.00', 'vat_rate' => '22.00', 'natura' => null, 'withholding_applies' => false]),
        new OA\Property(property: 'withholding', type: 'object', nullable: true, example: ['type' => 'RT01', 'reason' => 'A', 'rate' => '20.00', 'taxable_percent' => '100.00', 'includes_fund' => false, 'base' => '1000.00', 'amount' => '200.00']),
        new OA\Property(property: 'enasarco', type: 'object', nullable: true, example: ['rate' => '8.50', 'commissions' => '3000.00', 'contributory_base' => '1000.00', 'amount' => '85.00']),
        new OA\Property(property: 'stamp_duty_applied', type: 'boolean', example: false),
        new OA\Property(property: 'stamp_duty_amount', type: 'string', nullable: true, example: '2.00'),
        new OA\Property(property: 'stamp_duty_charged_to_customer', type: 'boolean', example: true),
        new OA\Property(property: 'vat_summary', type: 'array', nullable: true, items: new OA\Items(type: 'object'), example: [['rate' => '22.00', 'natura' => null, 'taxable' => '1040.00', 'tax' => '228.80', 'payability' => 'I']]),
        new OA\Property(property: 'legal_notices', type: 'array', items: new OA\Items(type: 'string')),
    ]
)]
class InvoiceResource {}
