<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Facility\Jobs\ReleaseNoShowBookingsJob;
use Modules\Facility\Models\FacilityBooking;

require_once __DIR__.'/FacilityTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 08:00:00');
    $this->tenant = facTenant();
    $this->manager = facMember($this->tenant, 'facility_manager');
    $this->alice = facMember($this->tenant, 'member');
    $this->bob = facMember($this->tenant, 'member');
    $this->room = facSpace($this->tenant, 'meeting_room');
    $this->desk = facSpace($this->tenant, 'desk');
    $this->otherDesk = facSpace($this->tenant, 'desk', ['building_id' => $this->desk->building_id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array<string, mixed>
 */
function facBookingPayload(string $spaceId, string $from, string $to, array $extra = []): array
{
    return ['space_id' => $spaceId, 'starts_at' => $from, 'ends_at' => $to, ...$extra];
}

test('a member books a room, overlaps and capacity are refused and adjacent bookings are allowed', function (): void {
    facAct($this->alice, $this->tenant);
    $booking = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T09:00:00Z', '2027-05-13T10:00:00Z', ['title' => 'Planning', 'attendees' => 4]))
        ->assertCreated()->assertJsonPath('data.status', 'booked')->assertJsonPath('data.user_id', $this->alice->id)->json('data');

    facAct($this->bob, $this->tenant);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T09:30:00Z', '2027-05-13T10:30:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T08:30:00Z', '2027-05-13T09:30:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T10:00:00Z', '2027-05-13T11:00:00Z'))->assertCreated();
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T12:00:00Z', '2027-05-13T13:00:00Z', ['attendees' => 9]))
        ->assertUnprocessable()->assertJsonValidationErrors(['attendees']);

    $check = $this->postJson('/api/v1/facility/bookings/check', ['space_id' => $this->room->id, 'starts_at' => '2027-05-13T09:00:00Z', 'ends_at' => '2027-05-13T10:00:00Z'])
        ->assertOk()->json('data');
    expect($check['available'])->toBeFalse()
        ->and(array_column($check['violations'], 'code'))->toBe(['conflict'])
        ->and($check['conflicts'][0]['id'])->toBe($booking['id']);

    $free = $this->postJson('/api/v1/facility/bookings/check', ['space_id' => $this->room->id, 'starts_at' => '2027-05-13T14:00:00Z', 'ends_at' => '2027-05-13T15:00:00Z'])->assertOk()->json('data');
    expect($free['available'])->toBeTrue()->and($free['conflicts'])->toBe([]);

    $available = $this->getJson('/api/v1/facility/spaces/available?starts_at=2027-05-13T09:15:00Z&ends_at=2027-05-13T09:45:00Z&kind=meeting_room')->assertOk()->json('data');
    expect($available)->toBe([]);
    $this->getJson('/api/v1/facility/spaces/available?starts_at=2027-05-13T14:00:00Z&ends_at=2027-05-13T15:00:00Z&kind=meeting_room')->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/facility/bookings?from=2027-05-13&to=2027-05-13')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/facility/bookings?from=2027-05-14&to=2027-05-14')->assertOk()->assertJsonCount(0, 'data');
});

test('a booking cannot be in the past, too long or too far ahead', function (): void {
    facAct($this->alice, $this->tenant);

    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-12T07:00:00Z', '2027-05-12T09:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T07:00:00Z', '2027-05-13T17:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-12-01T09:00:00Z', '2027-12-01T10:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T10:00:00Z', '2027-05-13T09:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_at']);

    $this->room->update(['is_active' => false]);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T09:00:00Z', '2027-05-13T10:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['space_id']);
});

test('the daily limit per person follows the rule of the kind of space', function (): void {
    facAct($this->alice, $this->tenant);
    $first = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->desk->id, '2027-05-13T07:00:00Z', '2027-05-13T15:00:00Z'))->assertCreated()->json('data');

    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-13T07:00:00Z', '2027-05-13T15:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-14T07:00:00Z', '2027-05-14T15:00:00Z'))->assertCreated();

    $this->postJson("/api/v1/facility/bookings/{$first['id']}/cancel", ['reason' => 'Smart working'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-13T07:00:00Z', '2027-05-13T15:00:00Z'))->assertCreated();

    $this->getJson('/api/v1/facility/booking-rules')->assertOk()->assertJsonPath('data.0.space_kind', 'office');

    facAct($this->alice, $this->tenant);
    $this->putJson('/api/v1/facility/booking-rules/desk', ['max_bookings_per_user_per_day' => null, 'max_minutes_per_booking' => 720, 'max_advance_days' => 90, 'checkin_grace_minutes' => 15, 'requires_checkin' => true])->assertForbidden();

    facAct($this->manager, $this->tenant);
    $this->putJson('/api/v1/facility/booking-rules/desk', ['max_bookings_per_user_per_day' => 2, 'max_minutes_per_booking' => 720, 'max_advance_days' => 90, 'checkin_grace_minutes' => 15, 'requires_checkin' => true])
        ->assertOk()->assertJsonPath('data.max_bookings_per_user_per_day', 2);

    facAct($this->alice, $this->tenant);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->desk->id, '2027-05-13T07:00:00Z', '2027-05-13T15:00:00Z'))->assertCreated();
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T07:00:00Z', '2027-05-13T08:00:00Z'))->assertCreated();
});

test('check-in opens shortly before the start and a booking nobody checked in to is released as a no-show', function (): void {
    facAct($this->alice, $this->tenant);
    $aliceBooking = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->desk->id, '2027-05-13T08:00:00Z', '2027-05-13T16:00:00Z'))->assertCreated()->json('data');
    facAct($this->bob, $this->tenant);
    $bobBooking = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-13T08:00:00Z', '2027-05-13T16:00:00Z'))->assertCreated()->json('data');

    facAct($this->alice, $this->tenant);
    $this->postJson("/api/v1/facility/bookings/{$aliceBooking['id']}/check-in")->assertUnprocessable();

    $this->travelTo('2027-05-13 07:50:00');
    facAct($this->bob, $this->tenant);
    $this->postJson("/api/v1/facility/bookings/{$aliceBooking['id']}/check-in")->assertForbidden();
    facAct($this->alice, $this->tenant);
    $this->postJson("/api/v1/facility/bookings/{$aliceBooking['id']}/check-in")->assertOk()->assertJsonPath('data.status', 'checked_in');
    $this->postJson("/api/v1/facility/bookings/{$aliceBooking['id']}/check-in")->assertUnprocessable();

    $this->travelTo('2027-05-13 08:10:00');
    (new ReleaseNoShowBookingsJob($this->tenant->id))->handle(app(TenantContext::class));
    expect(FacilityBooking::query()->find($bobBooking['id'])->status)->toBe('booked');

    $this->travelTo('2027-05-13 08:20:00');
    (new ReleaseNoShowBookingsJob($this->tenant->id))->handle(app(TenantContext::class));
    expect(FacilityBooking::query()->find($bobBooking['id'])->status)->toBe('no_show')
        ->and(FacilityBooking::query()->find($aliceBooking['id'])->status)->toBe('checked_in');

    facAct($this->alice, $this->tenant);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-13T08:20:00Z', '2027-05-13T12:00:00Z'))
        ->assertUnprocessable()->assertJsonValidationErrors(['starts_at']);

    facAct($this->manager, $this->tenant);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->otherDesk->id, '2027-05-13T08:20:00Z', '2027-05-13T12:00:00Z', ['user_id' => $this->manager->id]))->assertCreated();

    $this->travelTo('2027-05-13 17:00:00');
    (new ReleaseNoShowBookingsJob($this->tenant->id))->handle(app(TenantContext::class));
    expect(FacilityBooking::query()->find($aliceBooking['id'])->status)->toBe('completed');
});

test('only the owner or a manager cancels a booking and a manager books on behalf of others', function (): void {
    facAct($this->alice, $this->tenant);
    $booking = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T09:00:00Z', '2027-05-13T10:00:00Z'))->assertCreated()->json('data');
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-14T09:00:00Z', '2027-05-14T10:00:00Z', ['user_id' => $this->bob->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['user_id']);

    facAct($this->bob, $this->tenant);
    $this->postJson("/api/v1/facility/bookings/{$booking['id']}/cancel")->assertForbidden();

    facAct($this->manager, $this->tenant);
    $onBehalf = $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-14T09:00:00Z', '2027-05-14T10:00:00Z', ['user_id' => $this->bob->id]))
        ->assertCreated()->assertJsonPath('data.user_id', $this->bob->id)->json('data');
    $this->postJson("/api/v1/facility/bookings/{$booking['id']}/cancel", ['reason' => 'Room needed'])->assertOk()->assertJsonPath('data.cancel_reason', 'Room needed');
    $this->postJson("/api/v1/facility/bookings/{$booking['id']}/cancel")->assertUnprocessable();

    $this->getJson('/api/v1/facility/bookings?mine=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/facility/bookings?member_id={$this->bob->id}&active=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $onBehalf['id']);

    facAct($this->alice, $this->tenant);
    $this->postJson('/api/v1/facility/bookings', facBookingPayload($this->room->id, '2027-05-13T09:00:00Z', '2027-05-13T10:00:00Z'))->assertCreated();
});

test('a recurring booking books every occurrence, refuses a series with conflicts and skips them on request', function (): void {
    facAct($this->alice, $this->tenant);
    $series = $this->postJson('/api/v1/facility/booking-series', [
        'space_id' => $this->desk->id, 'recurrence' => 'weekly', 'weekdays' => [1, 3],
        'start_date' => '2027-05-17', 'until_date' => '2027-06-11', 'start_time' => '09:00', 'end_time' => '17:00', 'title' => 'Office days',
    ])->assertCreated()->assertJsonPath('data.created', 8)->assertJsonPath('data.skipped', [])->json('data');

    $first = FacilityBooking::query()->where('series_id', $series['id'])->orderBy('starts_at')->first();
    expect(FacilityBooking::query()->where('series_id', $series['id'])->count())->toBe(8)
        ->and($first->starts_at->toIso8601String())->toBe('2027-05-17T07:00:00+00:00')
        ->and($first->ends_at->toIso8601String())->toBe('2027-05-17T15:00:00+00:00');

    facAct($this->bob, $this->tenant);
    $payload = ['space_id' => $this->desk->id, 'recurrence' => 'daily', 'start_date' => '2027-05-24', 'until_date' => '2027-05-26', 'start_time' => '09:00', 'end_time' => '17:00'];
    $this->postJson('/api/v1/facility/booking-series', $payload)->assertUnprocessable()->assertJsonValidationErrors(['series']);
    expect(FacilityBooking::query()->where('user_id', $this->bob->id)->count())->toBe(0);

    $skipped = $this->postJson('/api/v1/facility/booking-series', [...$payload, 'skip_conflicts' => true])
        ->assertCreated()->assertJsonPath('data.created', 1)->json('data.skipped');
    expect(array_column($skipped, 'date'))->toBe(['2027-05-24', '2027-05-26']);

    facAct($this->alice, $this->tenant);
    $cancelled = $this->postJson("/api/v1/facility/booking-series/{$series['id']}/cancel")->assertOk()->json('data');
    expect($cancelled['status'])->toBe('cancelled')->and($cancelled['cancelled_bookings'])->toBe(8)
        ->and(FacilityBooking::query()->where('series_id', $series['id'])->where('status', 'booked')->count())->toBe(0);
    $this->postJson("/api/v1/facility/booking-series/{$series['id']}/cancel")->assertUnprocessable();

    $this->postJson('/api/v1/facility/booking-series', [...$payload, 'recurrence' => 'weekly', 'until_date' => '2028-12-31'])->assertUnprocessable();
});

test('the wall clock of the company decides the day of a booking across the daylight saving change', function (): void {
    facAct($this->alice, $this->tenant);
    $this->travelTo('2027-03-20 08:00:00');
    $this->postJson('/api/v1/facility/booking-series', [
        'space_id' => $this->room->id, 'recurrence' => 'daily', 'start_date' => '2027-03-27', 'until_date' => '2027-03-29', 'start_time' => '10:00', 'end_time' => '11:00',
    ])->assertCreated()->assertJsonPath('data.created', 3);

    $starts = FacilityBooking::query()->orderBy('starts_at')->get()->map(fn (FacilityBooking $booking): string => $booking->starts_at->format('Y-m-d H:i'))->all();
    expect($starts)->toBe(['2027-03-27 09:00', '2027-03-28 08:00', '2027-03-29 08:00']);
});
