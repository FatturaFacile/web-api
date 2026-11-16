<?php

declare(strict_types=1);

namespace Modules\TravelExpense\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the business trip and travel expense endpoints.
 */
#[OA\Tag(name: 'Travel', description: 'Business trips: requests with approval, itinerary, per diem (IT trasferta, CH Spesenreglement), mileage, advance reconciliation and trip report')]
#[OA\Response(response: 'TravelUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TravelForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TravelNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'TravelUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'TravelTripRequest', type: 'object', required: ['purpose', 'destination_city', 'destination_country', 'start_date', 'end_date'], properties: [
    new OA\Property(property: 'user_id', description: 'Employee; only owners, administrators, HR and accountants can request a trip for somebody else', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'purpose', type: 'string', maxLength: 500, example: 'Customer workshop in Lugano'),
    new OA\Property(property: 'destination_city', type: 'string', maxLength: 120, example: 'Lugano'),
    new OA\Property(property: 'destination_country', description: 'ISO 3166-1 alpha-2; domestic when equal to the rate jurisdiction', type: 'string', example: 'CH'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'end_date', type: 'string', format: 'date'),
    new OA\Property(property: 'departure_time', description: 'H:i; the first day counts from midnight when absent', type: 'string', example: '07:30', nullable: true),
    new OA\Property(property: 'return_time', description: 'H:i; the last day counts to midnight when absent', type: 'string', example: '19:00', nullable: true),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF'], nullable: true),
    new OA\Property(property: 'rate_jurisdiction', description: 'Rate tables in force: IT (trasferta) or CH (Spesenreglement); default from the country of the company', type: 'string', enum: ['IT', 'CH'], nullable: true),
    new OA\Property(property: 'estimated_budget', type: 'string', example: '850.00', nullable: true),
    new OA\Property(property: 'advance_amount', description: 'Advance asked for; never above the estimated budget', type: 'string', example: '300.00', nullable: true),
])]
#[OA\Schema(schema: 'TravelApproveRequest', type: 'object', properties: [
    new OA\Property(property: 'advance_amount', description: 'Replaces the advance asked for', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelRejectRequest', type: 'object', required: ['reason'], properties: [
    new OA\Property(property: 'reason', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'TravelAdvancePaidRequest', type: 'object', required: ['paid_at'], properties: [
    new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'TravelLegRequest', type: 'object', required: ['leg_type', 'to_location'], properties: [
    new OA\Property(property: 'leg_type', type: 'string', enum: ['flight', 'train', 'car', 'hotel']),
    new OA\Property(property: 'sequence', description: 'Position in the itinerary; appended when absent', type: 'integer', nullable: true),
    new OA\Property(property: 'from_location', type: 'string', nullable: true),
    new OA\Property(property: 'to_location', type: 'string'),
    new OA\Property(property: 'departs_at', description: 'Departure, or check-in for a hotel (required for a hotel)', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'arrives_at', description: 'Arrival, or check-out for a hotel (required for a hotel)', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'provider', type: 'string', nullable: true),
    new OA\Property(property: 'booking_reference', type: 'string', nullable: true),
    new OA\Property(property: 'booking_status', type: 'string', enum: ['planned', 'booked', 'cancelled'], nullable: true),
    new OA\Property(property: 'booked_cost', type: 'string', example: '120.00', nullable: true),
    new OA\Property(property: 'currency', description: 'Must be the currency of the trip', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelPerDiemRequest', description: 'Without days the meals and lodging already recorded are kept.', type: 'object', properties: [
    new OA\Property(property: 'days', type: 'array', nullable: true, items: new OA\Items(type: 'object', required: ['day'], properties: [
        new OA\Property(property: 'day', type: 'string', format: 'date'),
        new OA\Property(property: 'breakfast_provided', type: 'boolean', nullable: true),
        new OA\Property(property: 'lunch_provided', type: 'boolean', nullable: true),
        new OA\Property(property: 'dinner_provided', type: 'boolean', nullable: true),
        new OA\Property(property: 'lodging_provided', type: 'boolean', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'TravelMileageClaimRequest', type: 'object', required: ['travel_date', 'from_location', 'to_location', 'vehicle_type', 'distance_km'], properties: [
    new OA\Property(property: 'travel_date', description: 'Within the dates of the trip', type: 'string', format: 'date'),
    new OA\Property(property: 'from_location', type: 'string'),
    new OA\Property(property: 'to_location', type: 'string'),
    new OA\Property(property: 'vehicle_type', type: 'string', enum: ['car', 'motorcycle', 'bicycle']),
    new OA\Property(property: 'round_trip', description: 'Doubles the kilometres', type: 'boolean', nullable: true),
    new OA\Property(property: 'distance_km', description: 'One way', type: 'string', example: '84.50'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelLinkExpenseRequest', type: 'object', required: ['expense_id'], properties: [
    new OA\Property(property: 'expense_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'TravelPerDiemRateRequest', type: 'object', required: ['jurisdiction', 'year', 'scope', 'currency', 'daily_amount'], properties: [
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'year', type: 'integer', example: 2027),
    new OA\Property(property: 'scope', type: 'string', enum: ['domestic', 'abroad']),
    new OA\Property(property: 'country', description: 'Destination country the row applies to; the generic row of the scope when absent', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'daily_amount', type: 'string', example: '46.48'),
    new OA\Property(property: 'tax_free_daily_amount', description: 'Daily tax-free threshold (IT art. 51 TUIR); absent means the whole allowance is tax free (CH flat expenses)', type: 'string', nullable: true),
    new OA\Property(property: 'lodging_deduction_percent', type: 'string', example: '33.3333', nullable: true),
    new OA\Property(property: 'breakfast_deduction_percent', type: 'string', nullable: true),
    new OA\Property(property: 'meal_deduction_percent', type: 'string', example: '33.3333', nullable: true),
    new OA\Property(property: 'deduct_each_meal', description: 'true: every provided meal is deducted (CH); false: the meal deduction applies once (IT)', type: 'boolean', nullable: true),
    new OA\Property(property: 'half_day_min_hours', description: 'Hours away from which a day counts in half (default 8)', type: 'integer', nullable: true),
    new OA\Property(property: 'full_day_min_hours', description: 'Hours away from which a day counts in full (default 12)', type: 'integer', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelMileageRateRequest', type: 'object', required: ['jurisdiction', 'year', 'vehicle_type', 'currency', 'rate_per_km'], properties: [
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'year', type: 'integer', example: 2027),
    new OA\Property(property: 'vehicle_type', type: 'string', enum: ['car', 'motorcycle', 'bicycle']),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'rate_per_km', type: 'string', example: '0.7000'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelSeedRatesRequest', type: 'object', required: ['jurisdiction', 'year'], properties: [
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'year', type: 'integer', example: 2027),
])]
#[OA\Schema(schema: 'TravelLeg', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'trip_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sequence', type: 'integer'),
    new OA\Property(property: 'leg_type', type: 'string', enum: ['flight', 'train', 'car', 'hotel']),
    new OA\Property(property: 'from_location', type: 'string', nullable: true),
    new OA\Property(property: 'to_location', type: 'string'),
    new OA\Property(property: 'departs_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'arrives_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'provider', type: 'string', nullable: true),
    new OA\Property(property: 'booking_reference', type: 'string', nullable: true),
    new OA\Property(property: 'booking_status', type: 'string', enum: ['planned', 'booked', 'cancelled']),
    new OA\Property(property: 'booked_cost', type: 'string', example: '120.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelPerDiemDay', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'day', type: 'string', format: 'date'),
    new OA\Property(property: 'hours_away', type: 'string', example: '24.00'),
    new OA\Property(property: 'day_factor', description: '1.00 full day, 0.50 half day, 0.00 below the thresholds', type: 'string'),
    new OA\Property(property: 'breakfast_provided', type: 'boolean'),
    new OA\Property(property: 'lunch_provided', type: 'boolean'),
    new OA\Property(property: 'dinner_provided', type: 'boolean'),
    new OA\Property(property: 'lodging_provided', type: 'boolean'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'gross_amount', type: 'string'),
    new OA\Property(property: 'deduction_amount', type: 'string'),
    new OA\Property(property: 'amount', description: 'Gross minus deductions', type: 'string'),
    new OA\Property(property: 'tax_free_amount', type: 'string'),
    new OA\Property(property: 'taxable_amount', description: 'Part that goes to payroll as taxable income', type: 'string'),
])]
#[OA\Schema(schema: 'TravelMileageClaim', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'trip_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'travel_date', type: 'string', format: 'date'),
    new OA\Property(property: 'from_location', type: 'string'),
    new OA\Property(property: 'to_location', type: 'string'),
    new OA\Property(property: 'vehicle_type', type: 'string', enum: ['car', 'motorcycle', 'bicycle']),
    new OA\Property(property: 'round_trip', type: 'boolean'),
    new OA\Property(property: 'distance_km', type: 'string'),
    new OA\Property(property: 'rate_per_km', description: 'Rate snapshotted when the claim was saved', type: 'string'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'amount', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelTrip', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string', example: 'TR-2027-0001'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'purpose', type: 'string'),
    new OA\Property(property: 'destination_city', type: 'string'),
    new OA\Property(property: 'destination_country', type: 'string'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'end_date', type: 'string', format: 'date'),
    new OA\Property(property: 'departure_time', type: 'string', nullable: true),
    new OA\Property(property: 'return_time', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'rate_jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'estimated_budget', type: 'string'),
    new OA\Property(property: 'advance_amount', type: 'string'),
    new OA\Property(property: 'advance_paid_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected', 'completed', 'reconciled']),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejected_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'reconciled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'reconciled_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reconciled_expenses_total', type: 'string', nullable: true),
    new OA\Property(property: 'reconciled_per_diem_total', type: 'string', nullable: true),
    new OA\Property(property: 'reconciled_mileage_total', type: 'string', nullable: true),
    new OA\Property(property: 'reconciled_advance_total', type: 'string', nullable: true),
    new OA\Property(property: 'reconciled_balance', description: 'Positive: owed to the employee; negative: owed back by the employee', type: 'string', nullable: true),
    new OA\Property(property: 'reconciled_direction', type: 'string', enum: ['owed_to_employee', 'owed_by_employee', 'settled'], nullable: true),
    new OA\Property(property: 'legs', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelLeg')),
    new OA\Property(property: 'per_diem_days', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelPerDiemDay')),
    new OA\Property(property: 'mileage_claims', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelMileageClaim')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'TravelReconciliation', type: 'object', properties: [
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'per_diem_total', type: 'string'),
    new OA\Property(property: 'per_diem_taxable_total', type: 'string'),
    new OA\Property(property: 'mileage_total', type: 'string'),
    new OA\Property(property: 'expenses_total', description: 'Approved reimbursable linked expenses in the currency of the trip', type: 'string'),
    new OA\Property(property: 'total_due', type: 'string'),
    new OA\Property(property: 'advance_amount', type: 'string'),
    new OA\Property(property: 'advance_paid', description: 'The advance counts once its payment date is recorded', type: 'string'),
    new OA\Property(property: 'balance', description: 'Total due minus advance paid; positive is owed to the employee', type: 'string'),
    new OA\Property(property: 'balance_amount', description: 'Absolute value of the balance', type: 'string'),
    new OA\Property(property: 'direction', type: 'string', enum: ['owed_to_employee', 'owed_by_employee', 'settled']),
    new OA\Property(property: 'pending_expenses', description: 'Linked expenses still in draft or submitted: they block the reconciliation', type: 'integer'),
    new OA\Property(property: 'rejected_expenses', type: 'integer'),
    new OA\Property(property: 'expenses', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'expense_number', type: 'string'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'merchant_name', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'counted', type: 'boolean'),
    ])),
])]
#[OA\Schema(schema: 'TravelTripReport', type: 'object', properties: [
    new OA\Property(property: 'trip', ref: '#/components/schemas/TravelTrip'),
    new OA\Property(property: 'budget', type: 'object', properties: [
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'estimated', type: 'string'),
        new OA\Property(property: 'booked_itinerary', type: 'string'),
        new OA\Property(property: 'booked_by_type', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'actual', type: 'string'),
        new OA\Property(property: 'variance', description: 'Actual minus estimated', type: 'string'),
        new OA\Property(property: 'utilization_percent', type: 'string', nullable: true),
        new OA\Property(property: 'within_budget', type: 'boolean'),
    ]),
    new OA\Property(property: 'reconciliation', ref: '#/components/schemas/TravelReconciliation'),
    new OA\Property(property: 'frozen', description: 'true once the trip is reconciled: the figures are the frozen ones', type: 'boolean'),
])]
#[OA\Schema(schema: 'TravelPerDiemRate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'scope', type: 'string', enum: ['domestic', 'abroad']),
    new OA\Property(property: 'country', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'daily_amount', type: 'string'),
    new OA\Property(property: 'tax_free_daily_amount', type: 'string', nullable: true),
    new OA\Property(property: 'lodging_deduction_percent', type: 'string'),
    new OA\Property(property: 'breakfast_deduction_percent', type: 'string'),
    new OA\Property(property: 'meal_deduction_percent', type: 'string'),
    new OA\Property(property: 'deduct_each_meal', type: 'boolean'),
    new OA\Property(property: 'half_day_min_hours', type: 'integer'),
    new OA\Property(property: 'full_day_min_hours', type: 'integer'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'TravelMileageRate', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'jurisdiction', type: 'string', enum: ['IT', 'CH']),
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'vehicle_type', type: 'string', enum: ['car', 'motorcycle', 'bicycle']),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'rate_per_km', type: 'string'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
final class TravelExpenseOpenApi {}
