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
    ]
)]
class InvoiceResource {}
