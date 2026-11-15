<?php

declare(strict_types=1);

namespace Modules\Workshop\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and responses of the estimate, warranty claim and part consumption endpoints of the workshop.
 */
#[OA\Schema(schema: 'WorkshopEstimateRequest', type: 'object', required: ['vehicle_id', 'kind', 'currency'], properties: [
    new OA\Property(property: 'vehicle_id', description: 'Only on creation', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', description: 'Defaults to the owner of the vehicle', type: 'string', format: 'uuid'),
    new OA\Property(property: 'kind', type: 'string', enum: ['mechanics', 'bodywork', 'tyres', 'inspection', 'other']),
    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
    new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'mileage_in', type: 'integer', nullable: true),
    new OA\Property(property: 'complaint', type: 'string', nullable: true),
    new OA\Property(property: 'diagnosis', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', description: 'Only on creation', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkshopFolderLineRequest')),
])]
#[OA\Schema(schema: 'WorkshopDecisionRequest', type: 'object', properties: [
    new OA\Property(property: 'note', description: 'Reason or comment of the decision', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopWarrantyClaimRequest', type: 'object', required: ['folder_id', 'description'], properties: [
    new OA\Property(property: 'folder_id', description: 'Only on creation', type: 'string', format: 'uuid'),
    new OA\Property(property: 'folder_line_id', description: 'Only on creation', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'supplier_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'claimed_amount', description: 'Defaults to the net total of the line', type: 'string', example: '150.00'),
    new OA\Property(property: 'supplier_reference', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopWarrantySubmitRequest', type: 'object', properties: [
    new OA\Property(property: 'supplier_reference', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopWarrantyReimburseRequest', type: 'object', required: ['reimbursed_amount', 'reimbursed_on'], properties: [
    new OA\Property(property: 'reimbursed_amount', type: 'string', example: '120.00'),
    new OA\Property(property: 'reimbursed_on', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'WorkshopPartRequest', type: 'object', required: ['product_id', 'quantity'], properties: [
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'quantity', type: 'string', example: '2'),
    new OA\Property(property: 'folder_line_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopPartAvailabilityResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tracked', type: 'boolean'),
        new OA\Property(property: 'available', type: 'string', example: '12.0000'),
    ]),
])]
final class WorkshopEstimateOpenApi {}
