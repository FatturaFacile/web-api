<?php

declare(strict_types=1);

namespace Modules\Hospitality\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the lodging endpoints (room types, accommodations, rate plans, stays, folio, housekeeping).
 */
#[OA\Schema(schema: 'HospitalityRoomTypeRequest', description: 'A room type. On PUT every field is optional.', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 30),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'max_occupancy', type: 'integer', minimum: 1, maximum: 20),
    new OA\Property(property: 'base_rate', description: 'Nightly rate when no rate plan applies', type: 'number'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HospitalityAccommodationRequest', description: 'A physical room. On PUT every field is optional.', type: 'object', required: ['room_type_id', 'number'], properties: [
    new OA\Property(property: 'room_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', maxLength: 20),
    new OA\Property(property: 'floor', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['available', 'out_of_service']),
    new OA\Property(property: 'housekeeping_status', type: 'string', enum: ['clean', 'dirty', 'inspected']),
])]
#[OA\Schema(schema: 'HospitalityRatePlanRequest', description: 'A seasonal rate plan. On PUT every field is optional.', type: 'object', required: ['code', 'name', 'nightly_rate', 'valid_from'], properties: [
    new OA\Property(property: 'room_type_id', description: 'Empty for every room type', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'code', type: 'string', maxLength: 30),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'meal_plan', type: 'string', enum: ['room_only', 'bed_breakfast', 'half_board', 'full_board'], nullable: true),
    new OA\Property(property: 'nightly_rate', type: 'number'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'min_stay', type: 'integer', minimum: 1),
    new OA\Property(property: 'priority', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HospitalityStayRequest', type: 'object', required: ['guest_name', 'room_type_id', 'check_in_date', 'check_out_date', 'adults'], properties: [
    new OA\Property(property: 'guest_name', type: 'string', maxLength: 160),
    new OA\Property(property: 'guest_email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'room_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rate_plan_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'check_out_date', description: 'After check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'adults', type: 'integer', minimum: 1),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityCheckInRequest', type: 'object', properties: [
    new OA\Property(property: 'accommodation_id', description: 'Room of the booked type; empty picks the first free clean room', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'city_tax_per_person_night', type: 'number', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityFolioChargeRequest', type: 'object', required: ['category', 'description', 'unit_amount'], properties: [
    new OA\Property(property: 'category', type: 'string', enum: ['room', 'city_tax', 'extra', 'restaurant', 'other']),
    new OA\Property(property: 'description', type: 'string', maxLength: 190),
    new OA\Property(property: 'quantity', type: 'number'),
    new OA\Property(property: 'unit_amount', type: 'number'),
    new OA\Property(property: 'posted_on', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'HospitalityFolioPaymentRequest', type: 'object', required: ['amount', 'payment_method'], properties: [
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'card', 'bank_transfer', 'voucher', 'other']),
    new OA\Property(property: 'description', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityHousekeepingTaskRequest', type: 'object', required: ['accommodation_id', 'task_type'], properties: [
    new OA\Property(property: 'accommodation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'task_type', type: 'string', enum: ['checkout_clean', 'daily', 'inspection']),
    new OA\Property(property: 'due_on', type: 'string', format: 'date', description: 'Defaults to today'),
    new OA\Property(property: 'assigned_to', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityHousekeepingStartRequest', type: 'object', properties: [new OA\Property(property: 'assigned_to', type: 'string', format: 'uuid', nullable: true)])]
final class HospitalityLodgingOpenApi {}
