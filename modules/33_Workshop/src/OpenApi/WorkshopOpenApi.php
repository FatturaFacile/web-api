<?php

declare(strict_types=1);

namespace Modules\Workshop\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, payloads and responses shared by the workshop endpoints.
 */
#[OA\Tag(name: 'Workshop', description: 'Car workshops and body shops: work folders, vehicle sales and recurring services')]
#[OA\Response(response: 'WorkshopUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WorkshopForbidden', description: 'The role of the user in the company does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WorkshopNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'WorkshopUnprocessable', description: 'Validation error or workshop rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'WorkshopFolderRequest', type: 'object', required: ['vehicle_id', 'kind', 'currency'], properties: [
    new OA\Property(property: 'vehicle_id', description: 'Only on creation', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', description: 'Defaults to the owner of the vehicle', type: 'string', format: 'uuid'),
    new OA\Property(property: 'kind', type: 'string', enum: ['mechanics', 'bodywork', 'tyres', 'inspection', 'other']),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'promised_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'mileage_in', type: 'integer', nullable: true),
    new OA\Property(property: 'complaint', type: 'string', nullable: true),
    new OA\Property(property: 'diagnosis', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
])]
#[OA\Schema(schema: 'WorkshopFolderLineRequest', type: 'object', required: ['line_type', 'description', 'quantity', 'unit_price', 'tax_rate'], properties: [
    new OA\Property(property: 'line_type', type: 'string', enum: ['labour', 'part', 'paint', 'sublet', 'other']),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string', example: '1.5'),
    new OA\Property(property: 'unit_price', type: 'string', example: '120.00'),
    new OA\Property(property: 'discount_percentage', type: 'string', nullable: true),
    new OA\Property(property: 'tax_rate', description: 'Chosen by the user', type: 'string'),
    new OA\Property(property: 'technician_user_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopFolderActionRequest', type: 'object', properties: [
    new OA\Property(property: 'status', description: 'Status change only', type: 'string', enum: ['in_progress', 'completed', 'delivered', 'cancelled']),
    new OA\Property(property: 'mileage_out', type: 'integer', nullable: true),
    new OA\Property(property: 'valid_until', description: 'Quote only', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'date', description: 'Invoice date, defaults to today', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'WorkshopStockUnitRequest', type: 'object', required: ['stock_number', 'condition', 'make', 'model', 'list_price', 'currency'], properties: [
    new OA\Property(property: 'stock_number', type: 'string'),
    new OA\Property(property: 'condition', type: 'string', enum: ['new', 'used']),
    new OA\Property(property: 'vin', type: 'string', nullable: true),
    new OA\Property(property: 'license_plate', type: 'string', nullable: true),
    new OA\Property(property: 'make', type: 'string'),
    new OA\Property(property: 'model', type: 'string'),
    new OA\Property(property: 'year', type: 'integer', nullable: true),
    new OA\Property(property: 'mileage_km', type: 'integer'),
    new OA\Property(property: 'supplier_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'purchase_price', type: 'string', nullable: true),
    new OA\Property(property: 'list_price', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['in_stock', 'reserved']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'AutomotiveVehicleRequest', description: 'A customer vehicle. On PUT and PATCH every field is optional.', type: 'object', required: ['client_id', 'license_plate', 'make', 'model'], properties: [
    new OA\Property(property: 'client_id', description: 'Owner contact', type: 'string', format: 'uuid'),
    new OA\Property(property: 'license_plate', description: 'Stored upper case, unique in the company', type: 'string', maxLength: 20),
    new OA\Property(property: 'vin_chassis', type: 'string', maxLength: 30, nullable: true),
    new OA\Property(property: 'make', type: 'string', maxLength: 50),
    new OA\Property(property: 'model', type: 'string', maxLength: 50),
    new OA\Property(property: 'year', type: 'integer', nullable: true),
    new OA\Property(property: 'registration_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'mileage_km', type: 'integer', minimum: 0),
])]
#[OA\Schema(schema: 'WorkshopSellRequest', type: 'object', required: ['buyer_contact_id', 'sale_date', 'sale_price', 'tax_rate'], properties: [
    new OA\Property(property: 'buyer_contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sale_date', type: 'string', format: 'date'),
    new OA\Property(property: 'sale_price', description: 'Net price', type: 'string'),
    new OA\Property(property: 'tax_rate', description: 'Chosen by the user; margin taxation is not computed', type: 'string'),
    new OA\Property(property: 'license_plate', description: 'Required when the stock unit has none', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopRecurringServiceRequest', type: 'object', required: ['vehicle_id', 'service_type', 'name', 'interval', 'start_date', 'currency', 'unit_price', 'tax_rate'], properties: [
    new OA\Property(property: 'vehicle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tire_hotel_record_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'service_type', type: 'string', enum: ['tyre_storage', 'maintenance_plan', 'other']),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'interval', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'yearly']),
    new OA\Property(property: 'interval_count', type: 'integer', nullable: true),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'unit_price', type: 'string'),
    new OA\Property(property: 'tax_rate', type: 'string'),
])]
#[OA\Schema(schema: 'WorkshopRecordResponse', description: 'A workshop record: its attributes, decimals as strings', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'WorkshopRecordCollection', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'links', type: 'object'),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'WorkshopDocumentResponse', description: 'A sales document made from a folder', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_type', type: 'string', enum: ['quote', 'delivery_note', 'invoice']),
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'number', type: 'string'),
    ]),
])]
final class WorkshopOpenApi {}
