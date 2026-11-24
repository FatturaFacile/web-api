<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = evTenant('Congressi');
    $this->owner = evMember($this->tenant, 'owner');
    evAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function evTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function evMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@events-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function evAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * A published event with one ticket type.
 *
 * @param  array<string, mixed>  $event
 * @param  array<string, mixed>  $ticket
 * @return array{event: array<string, mixed>, ticket: array<string, mixed>}
 */
function evPublished(array $event = [], array $ticket = []): array
{
    $created = test()->postJson('/api/v1/events', [
        'code' => 'EV-'.Str::upper(Str::random(5)),
        'name' => 'Annual conference',
        'starts_at' => '2027-09-10 09:00:00',
        'ends_at' => '2027-09-10 18:00:00',
        'capacity' => 2,
        ...$event,
    ])->assertCreated()->json('data');
    $type = test()->postJson('/api/v1/events/ticket-types', ['event_id' => $created['id'], 'name' => 'Standard', 'price' => '50', ...$ticket])->assertCreated()->json('data');
    test()->postJson("/api/v1/events/{$created['id']}/publish")->assertOk();

    return ['event' => $created, 'ticket' => $type];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function evRegister(array $setup, string $name, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/events/registrations', ['event_id' => $setup['event']['id'], 'ticket_type_id' => $setup['ticket']['id'], 'attendee_name' => $name, ...$overrides]);
}

it('creates an event, validates it and publishes it only with a ticket type', function (): void {
    $event = $this->postJson('/api/v1/events', ['code' => 'EV-1', 'name' => 'Open day', 'starts_at' => '2027-09-10 09:00:00', 'ends_at' => '2027-09-10 18:00:00'])->assertCreated()->json('data');
    expect($event['status'])->toBe('draft');

    $this->postJson('/api/v1/events', ['code' => 'EV-1', 'name' => 'Duplicate', 'starts_at' => '2027-09-10 09:00:00', 'ends_at' => '2027-09-10 18:00:00'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/events', ['code' => 'EV-2', 'name' => 'Backwards', 'starts_at' => '2027-09-10 09:00:00', 'ends_at' => '2027-09-09 18:00:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    $this->postJson("/api/v1/events/{$event['id']}/publish")->assertUnprocessable()->assertJsonValidationErrors('event');
    $this->postJson('/api/v1/events/registrations', ['event_id' => $event['id'], 'ticket_type_id' => (string) Str::uuid(), 'attendee_name' => 'Anna'])->assertUnprocessable()->assertJsonValidationErrors('ticket_type_id');

    $this->postJson('/api/v1/events/ticket-types', ['event_id' => $event['id'], 'name' => 'Standard', 'price' => '50'])->assertCreated();
    $this->postJson('/api/v1/events/sessions', ['event_id' => $event['id'], 'title' => 'Keynote', 'speaker' => 'Dr. Verdi', 'starts_at' => '2027-09-10 10:00:00', 'ends_at' => '2027-09-10 11:00:00'])->assertCreated();
    $this->postJson("/api/v1/events/{$event['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');

    $shown = $this->getJson("/api/v1/events/{$event['id']}")->assertOk()->json('data');
    expect($shown['sessions'])->toHaveCount(1)->and($shown['ticket_types'])->toHaveCount(1)->and($shown['confirmed_count'])->toBe(0);
    $this->deleteJson("/api/v1/events/{$event['id']}")->assertUnprocessable();
});

it('registers within capacity and sends the rest to the waiting list', function (): void {
    $setup = evPublished();

    $first = evRegister($setup, 'Anna', ['attendee_email' => 'anna@example.org'])->assertCreated()->json('data');
    $second = evRegister($setup, 'Bruno')->assertCreated()->json('data');
    $third = evRegister($setup, 'Carla')->assertCreated()->json('data');

    expect($first['status'])->toBe('confirmed')
        ->and($first['price'])->toBe('50.00')
        ->and(strlen($first['qr_token']))->toBe(40)
        ->and($first['qr_token'])->not->toBe($second['qr_token'])
        ->and($second['status'])->toBe('confirmed')
        ->and($third['status'])->toBe('waitlisted');

    $this->getJson("/api/v1/events/{$setup['event']['id']}")->assertOk()->assertJsonPath('data.confirmed_count', 2)->assertJsonPath('data.waitlisted_count', 1);
    $this->getJson("/api/v1/events/registrations?event_id={$setup['event']['id']}&status=waitlisted")->assertOk()->assertJsonCount(1, 'data');
});

it('refuses registrations to a full event without a waiting list and to an unpublished one', function (): void {
    $setup = evPublished(['capacity' => 1, 'waitlist_enabled' => false]);
    evRegister($setup, 'Anna')->assertCreated();
    evRegister($setup, 'Bruno')->assertUnprocessable()->assertJsonValidationErrors('event');

    $draft = $this->postJson('/api/v1/events', ['code' => 'EV-D', 'name' => 'Draft', 'starts_at' => '2027-09-10 09:00:00', 'ends_at' => '2027-09-10 18:00:00'])->assertCreated()->json('data');
    $ticket = $this->postJson('/api/v1/events/ticket-types', ['event_id' => $draft['id'], 'name' => 'Std', 'price' => '10'])->assertCreated()->json('data');
    evRegister(['event' => $draft, 'ticket' => $ticket], 'Carla')->assertUnprocessable()->assertJsonValidationErrors('event');
});

it('applies the quantity of the ticket type besides the event capacity', function (): void {
    $setup = evPublished(['capacity' => 10], ['quantity' => 1]);
    $other = $this->postJson('/api/v1/events/ticket-types', ['event_id' => $setup['event']['id'], 'name' => 'VIP', 'price' => '120'])->assertCreated()->json('data');

    evRegister($setup, 'Anna')->assertCreated()->assertJsonPath('data.status', 'confirmed');
    evRegister($setup, 'Bruno')->assertCreated()->assertJsonPath('data.status', 'waitlisted');
    evRegister(['event' => $setup['event'], 'ticket' => $other], 'Carla')->assertCreated()->assertJsonPath('data.status', 'confirmed');
});

it('promotes the oldest waiting registration that fits when a place is freed', function (): void {
    $setup = evPublished();
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');
    evRegister($setup, 'Bruno')->assertCreated();
    $carla = evRegister($setup, 'Carla')->assertCreated()->json('data');
    $dario = evRegister($setup, 'Dario')->assertCreated()->json('data');

    $this->postJson("/api/v1/events/registrations/{$anna['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson("/api/v1/events/registrations/{$carla['id']}")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->getJson("/api/v1/events/registrations/{$dario['id']}")->assertOk()->assertJsonPath('data.status', 'waitlisted');
    $this->postJson("/api/v1/events/registrations/{$anna['id']}/cancel")->assertUnprocessable();

    $this->putJson("/api/v1/events/{$setup['event']['id']}", ['capacity' => 3])->assertOk();
    $this->getJson("/api/v1/events/registrations/{$dario['id']}")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->putJson("/api/v1/events/{$setup['event']['id']}", ['capacity' => 1])->assertUnprocessable()->assertJsonValidationErrors('capacity');
});

it('checks attendees in by QR token once, only when confirmed', function (): void {
    $setup = evPublished(['capacity' => 1]);
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');
    $bruno = evRegister($setup, 'Bruno')->assertCreated()->json('data');

    $this->postJson('/api/v1/events/check-in', ['qr_token' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('qr_token');
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $bruno['qr_token']])->assertUnprocessable()->assertJsonValidationErrors('qr_token');
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token'], 'event_id' => (string) Str::uuid()])->assertUnprocessable();

    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token'], 'event_id' => $setup['event']['id']])->assertOk()->assertJsonPath('data.checked_in_at', '2027-06-15 09:00:00');
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token']])->assertUnprocessable();
    $this->postJson("/api/v1/events/registrations/{$anna['id']}/cancel")->assertUnprocessable();
    $this->getJson("/api/v1/events/registrations?event_id={$setup['event']['id']}&checked_in=1")->assertOk()->assertJsonCount(1, 'data');
});

it('renders the QR code of a registration as a PNG data URI', function (): void {
    $setup = evPublished();
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');

    $qr = $this->getJson("/api/v1/events/registrations/{$anna['id']}/qr")->assertOk()->json('data');

    expect($qr['qr_token'])->toBe($anna['qr_token'])->and($qr['data_uri'])->toStartWith('data:image/png;base64,');
});

it('reports expected and collected revenue, occupancy and no-shows', function (): void {
    $setup = evPublished(['capacity' => 4]);
    $vip = $this->postJson('/api/v1/events/ticket-types', ['event_id' => $setup['event']['id'], 'name' => 'VIP', 'price' => '120'])->assertCreated()->json('data');
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');
    $bruno = evRegister($setup, 'Bruno')->assertCreated()->json('data');
    $carla = evRegister(['event' => $setup['event'], 'ticket' => $vip], 'Carla')->assertCreated()->json('data');
    $dario = evRegister($setup, 'Dario')->assertCreated()->json('data');

    $this->postJson("/api/v1/events/registrations/{$anna['id']}/pay")->assertOk()->assertJsonPath('data.paid_at', '2027-06-15 09:00:00');
    $this->postJson("/api/v1/events/registrations/{$carla['id']}/pay")->assertOk();
    $this->postJson("/api/v1/events/registrations/{$dario['id']}/cancel")->assertOk();
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token']])->assertOk();
    $this->postJson("/api/v1/events/registrations/{$dario['id']}/pay")->assertUnprocessable();

    $report = $this->getJson("/api/v1/events/{$setup['event']['id']}/report")->assertOk()->json('data');

    expect($report['confirmed'])->toBe(3)
        ->and($report['cancelled'])->toBe(1)
        ->and($report['checked_in'])->toBe(1)
        ->and($report['no_show'])->toBe(2)
        ->and($report['occupancy_percent'])->toBe('75.00')
        ->and($report['expected_revenue'])->toBe('220.00')
        ->and($report['collected_revenue'])->toBe('170.00')
        ->and($report['outstanding_revenue'])->toBe('50.00')
        ->and(collect($report['by_ticket_type'])->firstWhere('name', 'VIP')['expected_revenue'])->toBe('120.00')
        ->and($bruno['status'])->toBe('confirmed');
});

it('cancels an event with its open registrations and closes registration on close', function (): void {
    $setup = evPublished();
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');

    $this->postJson("/api/v1/events/{$setup['event']['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    evRegister($setup, 'Bruno')->assertUnprocessable();
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token']])->assertOk();

    $other = evPublished();
    $registration = evRegister($other, 'Carla')->assertCreated()->json('data');
    $this->postJson("/api/v1/events/{$other['event']['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson("/api/v1/events/registrations/{$registration['id']}")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->deleteJson("/api/v1/events/ticket-types/{$other['ticket']['id']}")->assertUnprocessable();
});

it('keeps the records inside the company', function (): void {
    $setup = evPublished();
    $anna = evRegister($setup, 'Anna')->assertCreated()->json('data');
    $other = evTenant('Altra');
    evAct(evMember($other, 'owner'), $other);

    $this->getJson("/api/v1/events/{$setup['event']['id']}")->assertNotFound();
    $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/events/check-in', ['qr_token' => $anna['qr_token']])->assertUnprocessable();
    evRegister($setup, 'Mallory')->assertUnprocessable()->assertJsonValidationErrors('event_id');
});
