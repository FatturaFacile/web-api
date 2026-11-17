<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'ServiceManagement',
    description: 'Enterprise Service Management, ITSM, and Field Service endpoints (Tickets, Incidents, Problems, Changes, Work Orders, Service Contracts)'
)]
#[OA\Schema(
    schema: 'TicketResource',
    title: 'Ticket Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ticket_number', type: 'string', example: 'TCK-20261029-ABC123'),
        new OA\Property(property: 'title', type: 'string', example: 'Network latency in Milan data center'),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'assigned', 'in_progress', 'on_hold', 'escalated', 'resolved', 'closed', 'cancelled']),
        new OA\Property(property: 'is_sla_breached', type: 'boolean', example: false),
    ]
)]
#[OA\Schema(
    schema: 'WorkOrderResource',
    title: 'Work Order Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_order_number', type: 'string', example: 'WO-20261029-XYZ789'),
        new OA\Property(property: 'title', type: 'string', example: 'Emergency pump inspection'),
        new OA\Property(property: 'work_type', type: 'string', enum: ['corrective', 'preventive', 'inspection', 'installation', 'emergency', 'commissioning']),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'scheduled', 'dispatched', 'in_progress', 'on_hold', 'completed', 'cancelled']),
        new OA\Property(property: 'total_cost', description: 'Decimal string, never a float', type: 'string', example: '1250.00'),
    ]
)]
#[OA\Schema(
    schema: 'EquipmentPassportResource',
    title: 'Serialized Equipment Passport Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'serial_number', type: 'string', example: 'SN123'),
        new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'current_owner_customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'customer', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string', example: 'ACME SA'),
            new OA\Property(property: 'country', type: 'string', example: 'CH'),
        ]),
        new OA\Property(property: 'customer_type', type: 'string', enum: ['b2b', 'b2c']),
        new OA\Property(property: 'jurisdiction', type: 'string', enum: ['CH', 'IT']),
        new OA\Property(property: 'delivery_date', type: 'string', format: 'date', example: '2025-03-10'),
        new OA\Property(property: 'warranty_policy_type', type: 'string', enum: ['statutory_ch_24m', 'statutory_it_b2c_24m', 'statutory_it_b2b_12m', 'extended_care_pack']),
        new OA\Property(property: 'warranty_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'warranty_expiry_date', type: 'string', format: 'date', example: '2027-03-10'),
        new OA\Property(property: 'warranty_source', type: 'string', enum: ['statutory', 'contract_clause', 'extension']),
        new OA\Property(property: 'contract_warranty_months', type: 'integer', nullable: true),
        new OA\Property(property: 'installed_firmware_version', type: 'string', nullable: true),
        new OA\Property(property: 'extensions', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'components', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'RmaOrderResource',
    title: 'Depot Repair RMA Order Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rma_number', type: 'string', example: 'RMA-2026-000123'),
        new OA\Property(property: 'status', type: 'string', enum: ['awaiting_arrival', 'in_diagnosis', 'awaiting_estimate_approval', 'awaiting_parts', 'in_repair_testing', 'ready_for_return', 'returned', 'closed', 'cancelled']),
        new OA\Property(property: 'warranty_status', type: 'string', enum: ['covered_in_warranty', 'out_of_warranty_billable', 'goodwill']),
        new OA\Property(property: 'equipment_passport_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'serial_number', type: 'string'),
        new OA\Property(property: 'reported_symptom', type: 'string'),
        new OA\Property(property: 'estimate', type: 'object', properties: [
            new OA\Property(property: 'labour_total', type: 'string', example: '0.00'),
            new OA\Property(property: 'parts_total', type: 'string', example: '0.00'),
            new OA\Property(property: 'total', type: 'string', example: '0.00'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
            new OA\Property(property: 'preauthorized_threshold', type: 'string', example: '100.00'),
            new OA\Property(property: 'approval_status', type: 'string', enum: ['not_required', 'auto_approved_under_threshold', 'pending_customer_approval', 'approved', 'declined_return_unrepaired']),
            new OA\Property(property: 'decided_via', type: 'string', enum: ['public_link', 'staff', 'auto'], nullable: true),
            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        ]),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'loaner', ref: '#/components/schemas/LoanerResource', nullable: true),
        new OA\Property(property: 'vendor_claims', type: 'array', items: new OA\Items(ref: '#/components/schemas/VendorClaimResource')),
        new OA\Property(property: 'repair_outcome', type: 'string', enum: ['repaired', 'returned_unrepaired', 'replaced'], nullable: true),
        new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'LoanerResource',
    title: 'Loaner / Repair Account Register Entry',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rma_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'loaner_serial_number', type: 'string'),
        new OA\Property(property: 'register_type', type: 'string', enum: ['prestito_uso', 'conto_riparazione']),
        new OA\Property(property: 'status', type: 'string', enum: ['issued', 'returned', 'lost']),
        new OA\Property(property: 'is_overdue', type: 'boolean'),
        new OA\Property(property: 'days_outstanding', type: 'integer', example: 12),
    ]
)]
#[OA\Schema(
    schema: 'VendorClaimResource',
    title: 'Vendor Warranty Claim-back Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rma_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rma_number', type: 'string', nullable: true),
        new OA\Property(property: 'original_supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'acknowledged', 'approved_credit_note', 'approved_replacement', 'rejected', 'closed']),
        new OA\Property(property: 'claimed_amount', type: 'string', example: '45.00'),
        new OA\Property(property: 'credit_note_reference', type: 'string', nullable: true),
    ]
)]
final class ServiceManagementOpenApiSchemas {}
