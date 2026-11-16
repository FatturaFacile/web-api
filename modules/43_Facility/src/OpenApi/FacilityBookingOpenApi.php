<?php

declare(strict_types=1);

namespace Modules\Facility\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Operations of the bookings, the recurring bookings and the booking rules.
 */
#[OA\Get(path: '/api/v1/facility/booking-rules', operationId: 'listFacilityBookingRules', summary: 'Booking rules', description: 'The limits in force for every bookable kind: daily limit per person, maximum duration, advance days, check-in requirement and grace period.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], responses: [
    new OA\Response(response: 200, description: 'Booking rules', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityBookingRuleEffective'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Put(path: '/api/v1/facility/booking-rules/{kind}', operationId: 'updateFacilityBookingRule', summary: 'Set the booking rule of a kind', description: 'Facility managers only. A null limit means no limit.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingRuleRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['office', 'meeting_room', 'desk', 'parking', 'warehouse'])),
], responses: [
    new OA\Response(response: 200, description: 'Set the booking rule of a kind', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBookingRule')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/bookings', operationId: 'listFacilityBookings', summary: 'List bookings', description: 'Paginated bookings by start time, the data of a calendar. Filters: from and to (business days of the company), space_id, building_id, member_id (the person the booking is for), status, mine, active (booked or checked in).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    new OA\Parameter(name: 'space_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'building_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'member_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['booked', 'checked_in', 'completed', 'cancelled', 'no_show'])),
    new OA\Parameter(name: 'mine', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List bookings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityBooking'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/bookings', operationId: 'storeFacilityBooking', summary: 'Book a space', description: 'Books a space for an interval. Refused with 422 when the interval overlaps a booked or checked-in booking, when the space is not bookable, in the past or beyond the advance limit, when it exceeds the maximum duration or the capacity, or when the person reached the daily limit of the kind. Members book for themselves; facility managers can book for another member.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Book a space', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBooking')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/bookings/check', operationId: 'checkFacilityBooking', summary: 'Check a booking', description: 'Dry run of a booking: nothing is saved. Returns every rule the booking would break and the bookings it would collide with, to give feedback while the user picks the interval.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingCheckRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 200, description: 'Check a booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBookingCheckResult')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/bookings/{id}', operationId: 'showFacilityBooking', summary: 'Show a booking', description: 'A booking with its space and person.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBooking')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Post(path: '/api/v1/facility/bookings/{id}/check-in', operationId: 'checkInFacilityBooking', summary: 'Check in to a booking', description: 'The person arrives. Allowed from a few minutes before the start until the booking ends; a booking nobody checks in to is released as a no-show after the grace period of its kind of space.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Check in to a booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBooking')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Post(path: '/api/v1/facility/bookings/{id}/cancel', operationId: 'cancelFacilityBooking', summary: 'Cancel a booking', description: 'The owner of the booking or a facility manager cancels a booking that has not started being used.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingCancelRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Cancel a booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBooking')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/booking-series', operationId: 'listFacilityBookingSeries', summary: 'List recurring bookings', description: 'Paginated recurring bookings. Filters: mine, status.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(name: 'mine', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'cancelled'])),
    new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
], responses: [
    new OA\Response(response: 200, description: 'List recurring bookings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FacilityBookingSeries'))])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
])]
#[OA\Post(path: '/api/v1/facility/booking-series', operationId: 'storeFacilityBookingSeries', summary: 'Create a recurring booking', description: 'Expands a daily or weekly recurrence in the wall clock of the company and books every occurrence under the same rules as a single booking. The whole series is refused when an occurrence breaks a rule, unless skip_conflicts is set: then the free occurrences are booked and the skipped dates are reported.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingSeriesRequest')), tags: ['Facility'], responses: [
    new OA\Response(response: 201, description: 'Create a recurring booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBookingSeriesResult')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
#[OA\Get(path: '/api/v1/facility/booking-series/{id}', operationId: 'showFacilityBookingSeries', summary: 'Show a recurring booking', description: 'A recurring booking.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Show a recurring booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBookingSeries')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
])]
#[OA\Post(path: '/api/v1/facility/booking-series/{id}/cancel', operationId: 'cancelFacilityBookingSeries', summary: 'Cancel a recurring booking', description: 'Cancels the series and every booking of it that has not started.', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/FacilityBookingCancelRequest')), tags: ['Facility'], parameters: [
    new OA\Parameter(ref: '#/components/parameters/FacilityId'),
], responses: [
    new OA\Response(response: 200, description: 'Cancel a recurring booking', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FacilityBookingSeries')])),
    new OA\Response(ref: '#/components/responses/FacilityUnauthenticated', response: 401),
    new OA\Response(ref: '#/components/responses/FacilityForbidden', response: 403),
    new OA\Response(ref: '#/components/responses/FacilityNotFound', response: 404),
    new OA\Response(ref: '#/components/responses/FacilityUnprocessable', response: 422),
])]
final class FacilityBookingOpenApi {}
