<?php

declare(strict_types=1);

namespace Modules\Quality\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and records of the statistical process control, instrument calibration and customer complaint endpoints.
 */
#[OA\Schema(schema: 'QmsSpcCharacteristicRequest', required: ['code', 'name', 'subgroup_size'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'lower_spec_limit', type: 'number', nullable: true),
    new OA\Property(property: 'upper_spec_limit', type: 'number', nullable: true),
    new OA\Property(property: 'target', type: 'number', nullable: true),
    new OA\Property(property: 'subgroup_size', type: 'integer', minimum: 2, maximum: 10),
    new OA\Property(property: 'instrument_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'status', description: 'Only on update', type: 'string', enum: ['active', 'archived']),
])]
#[OA\Schema(schema: 'QmsSpcCharacteristic', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'lower_spec_limit', type: 'string', nullable: true),
    new OA\Property(property: 'upper_spec_limit', type: 'string', nullable: true),
    new OA\Property(property: 'target', type: 'string', nullable: true),
    new OA\Property(property: 'subgroup_size', type: 'integer'),
    new OA\Property(property: 'instrument_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'QmsSpcSampleRequest', required: ['measurements'], type: 'object', properties: [
    new OA\Property(property: 'measurements', description: 'Exactly subgroup_size values', type: 'array', items: new OA\Items(type: 'number')),
    new OA\Property(property: 'sampled_at', type: 'string', format: 'date-time', nullable: true, description: 'Defaults to now'),
    new OA\Property(property: 'lot', type: 'string', nullable: true),
    new OA\Property(property: 'instrument_id', description: 'Defaults to the instrument of the characteristic', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'QmsSpcSample', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'characteristic_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sampled_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'lot', type: 'string', nullable: true),
    new OA\Property(property: 'measurements', type: 'array', items: new OA\Items(type: 'number')),
    new OA\Property(property: 'mean', type: 'string'),
    new OA\Property(property: 'range', type: 'string'),
    new OA\Property(property: 'instrument_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'recorded_by', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'QmsSpcViolation', type: 'object', properties: [
    new OA\Property(property: 'sample_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'index', type: 'integer'),
    new OA\Property(property: 'chart', type: 'string', enum: ['xbar', 'range']),
    new OA\Property(property: 'rule', description: 'Nelson rule 1 to 6', type: 'integer', minimum: 1, maximum: 6),
])]
#[OA\Schema(schema: 'QmsSpcChart', type: 'object', properties: [
    new OA\Property(property: 'characteristic_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'subgroup_size', type: 'integer'),
    new OA\Property(property: 'subgroup_count', type: 'integer'),
    new OA\Property(property: 'is_reliable', description: 'At least 20 subgroups', type: 'boolean'),
    new OA\Property(property: 'in_control', type: 'boolean'),
    new OA\Property(property: 'points', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'limits', description: 'xbar and range: center, ucl, lcl; null below two subgroups', type: 'object', nullable: true),
    new OA\Property(property: 'capability', description: 'mean, sigma_within, sigma_overall, cp, cpk, pp, ppk, measurement_count', type: 'object', nullable: true),
    new OA\Property(property: 'violations', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsSpcViolation')),
])]
#[OA\Schema(schema: 'QmsInstrumentRequest', required: ['code', 'name', 'calibration_interval_days'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'manufacturer', type: 'string', nullable: true),
    new OA\Property(property: 'location', type: 'string', nullable: true),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'range_min', type: 'number', nullable: true),
    new OA\Property(property: 'range_max', type: 'number', nullable: true),
    new OA\Property(property: 'tolerance', description: 'Maximum permissible error', type: 'number', nullable: true),
    new OA\Property(property: 'calibration_interval_days', type: 'integer', minimum: 1, maximum: 3650),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsInstrument', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'manufacturer', type: 'string', nullable: true),
    new OA\Property(property: 'location', type: 'string', nullable: true),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'range_min', type: 'string', nullable: true),
    new OA\Property(property: 'range_max', type: 'string', nullable: true),
    new OA\Property(property: 'tolerance', type: 'string', nullable: true),
    new OA\Property(property: 'calibration_interval_days', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'quarantined', 'retired']),
    new OA\Property(property: 'last_calibrated_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'next_due_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'calibration_status', type: 'string', enum: ['valid', 'due_soon', 'overdue', 'quarantined', 'retired']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsCalibrationRequest', required: ['calibrated_on', 'result'], type: 'object', properties: [
    new OA\Property(property: 'calibrated_on', type: 'string', format: 'date'),
    new OA\Property(property: 'next_due_on', type: 'string', format: 'date', nullable: true, description: 'Defaults to calibrated_on plus the interval'),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'adjusted', 'out_of_tolerance']),
    new OA\Property(property: 'as_found_error', type: 'number', nullable: true),
    new OA\Property(property: 'as_left_error', type: 'number', nullable: true),
    new OA\Property(property: 'laboratory', type: 'string', nullable: true),
    new OA\Property(property: 'certificate_number', type: 'string', nullable: true),
    new OA\Property(property: 'certificate_document_id', description: 'Document of the company, by id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsCalibration', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'instrument_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'calibrated_on', type: 'string', format: 'date'),
    new OA\Property(property: 'next_due_on', type: 'string', format: 'date'),
    new OA\Property(property: 'result', type: 'string', enum: ['pass', 'adjusted', 'out_of_tolerance']),
    new OA\Property(property: 'as_found_error', type: 'string', nullable: true),
    new OA\Property(property: 'as_left_error', type: 'string', nullable: true),
    new OA\Property(property: 'laboratory', type: 'string', nullable: true),
    new OA\Property(property: 'certificate_number', type: 'string', nullable: true),
    new OA\Property(property: 'certificate_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'performed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'nonconformity_id', description: 'Raised automatically on out_of_tolerance', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsCalibrationImpact', type: 'object', properties: [
    new OA\Property(property: 'from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'to', type: 'string', format: 'date'),
    new OA\Property(property: 'sample_count', type: 'integer'),
    new OA\Property(property: 'characteristics', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'QmsComplaintRequest', required: ['customer_name', 'description'], type: 'object', properties: [
    new OA\Property(property: 'customer_name', type: 'string', maxLength: 190),
    new OA\Property(property: 'contact_id', description: 'Customer contact of the company, by id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date', nullable: true, description: 'Defaults to today'),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone', 'letter', 'web', 'in_person', 'other'], nullable: true),
    new OA\Property(property: 'reference', description: 'Order, delivery or lot reference', type: 'string', nullable: true),
    new OA\Property(property: 'product_description', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', maxLength: 4000),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical'], nullable: true),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'QmsComplaint', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string'),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date'),
    new OA\Property(property: 'channel', type: 'string'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'product_description', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'severity', type: 'string', enum: ['minor', 'major', 'critical']),
    new OA\Property(property: 'status', type: 'string', enum: ['received', 'investigating', 'replied', 'closed', 'rejected']),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'root_cause', type: 'string', nullable: true),
    new OA\Property(property: 'nonconformity_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'capa_action_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reply_text', type: 'string', nullable: true),
    new OA\Property(property: 'replied_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closure_notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'QmsComplaintInvestigateRequest', type: 'object', properties: [new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', nullable: true)])]
#[OA\Schema(schema: 'QmsComplaintRootCauseRequest', required: ['root_cause'], type: 'object', properties: [new OA\Property(property: 'root_cause', type: 'string', maxLength: 4000)])]
#[OA\Schema(schema: 'QmsComplaintLinkRequest', type: 'object', properties: [
    new OA\Property(property: 'nonconformity_id', description: 'Required without capa_action_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'capa_action_id', description: 'Required without nonconformity_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'QmsComplaintReplyRequest', required: ['reply_text'], type: 'object', properties: [new OA\Property(property: 'reply_text', type: 'string', maxLength: 8000)])]
#[OA\Schema(schema: 'QmsComplaintRejectRequest', required: ['reason'], type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)])]
final class QualityExtensionOpenApi {}
