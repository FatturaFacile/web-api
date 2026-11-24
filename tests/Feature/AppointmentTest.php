<?php

declare(strict_types=1);

use App\Events\Appointments\AppointmentCancelled;
use App\Events\Appointments\AppointmentConvertedToServiceReport;
use App\Events\Appointments\AppointmentScheduled;
use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Database\Seeders\AppointmentLookupSeeder;
use Database\Seeders\ServiceReportLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Event::fake([
        AppointmentScheduled::class,
        AppointmentCancelled::class,
        AppointmentConvertedToServiceReport::class,
    ]);

    // Seed lookups
    (new AppointmentLookupSeeder)->run();
    (new ServiceReportLookupSeeder)->run();

    // Create main tenant & context
    $this->tenant = Tenant::create([
        'name' => 'Mario Rossi Impianti Termoidraulici',
        'legal_name' => 'Mario Rossi Impianti Termoidraulici SAGL',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    // Create artisan user
    $this->user = User::create([
        'name' => 'Mario Rossi',
        'email' => 'mario@rossi-impianti.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    // Create technician user
    $this->technician = User::create([
        'name' => 'Marco Bianchi',
        'email' => 'marco@rossi-impianti.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->technician->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'technician']);

    // Create second technician user
    $this->apprentice = User::create([
        'name' => 'Luca Verdi',
        'email' => 'luca@rossi-impianti.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->apprentice->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'technician']);

    // Create customer contact
    $this->customer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'type' => 'company',
        'company_name' => 'Hotel Bellavista',
        'email' => 'direzione@hotelbellavista.ch',
        'phone' => '+41 91 800 11 22',
    ]);

    // Create support ticket
    $this->ticket = Ticket::create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->customer->id,
        'subject' => 'Guasto caldaia sala colazioni',
        'priority' => 'urgent',
        'status' => 'open',
    ]);

    $this->scheduledStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'appointment_status'))
        ->where('code', 'scheduled')
        ->first();
});

test('artisan schedules appointment with assigned technicians and conflict check passes', function (): void {
    $startsAt = Carbon::tomorrow()->setTime(9, 0, 0);
    $endsAt = Carbon::tomorrow()->setTime(11, 30, 0);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/appointments', [
            'contact_id' => $this->customer->id,
            'ticket_id' => $this->ticket->id,
            'title' => 'Sostituzione valvola a tre vie caldaia',
            'description' => 'Ispezione e sostituzione valvola termosifoni sala colazioni.',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'address' => 'Via Lungolago 14, 6900 Lugano',
            'latitude' => 46.0037,
            'longitude' => 8.9511,
            'estimated_travel_minutes' => 20,
            'technician_notes' => 'Codice allarme E04. Valvola di ricambio caricata sul furgone 2.',
            'customer_notes' => 'Accesso dal retro cucina.',
            'is_portal_visible' => true,
            'attendees' => [
                [
                    'user_id' => $this->technician->id,
                    'role' => 'lead',
                    'is_organizer' => true,
                ],
                [
                    'user_id' => $this->apprentice->id,
                    'role' => 'assistant',
                ],
            ],
        ])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('Sostituzione valvola a tre vie caldaia')
        ->and($response->json('data.appointment_number'))->toStartWith('APT-')
        ->and($response->json('data.contact.name'))->toBe('Hotel Bellavista')
        ->and(count($response->json('data.attendees')))->toBe(2);

    $this->assertDatabaseHas('appointments', [
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->customer->id,
        'ticket_id' => $this->ticket->id,
        'title' => 'Sostituzione valvola a tre vie caldaia',
        'is_portal_visible' => true,
    ]);

    $this->assertDatabaseHas('appointment_attendees', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->technician->id,
        'role' => 'lead',
    ]);

    Event::assertDispatched(AppointmentScheduled::class);
});

test('conflict detection prevents double-booking a technician for overlapping appointments', function (): void {
    $startsAt = Carbon::tomorrow()->setTime(14, 0, 0);
    $endsAt = Carbon::tomorrow()->setTime(16, 0, 0);

    // Create first appointment
    $firstResponse = $this->actingAs($this->user)
        ->postJson('/api/v1/appointments', [
            'contact_id' => $this->customer->id,
            'title' => 'Primo appuntamento',
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'attendees' => [
                ['user_id' => $this->technician->id, 'role' => 'lead'],
            ],
        ])
        ->assertCreated();

    // Try to book overlapping appointment for same technician
    $overlapStarts = Carbon::tomorrow()->setTime(15, 0, 0);
    $overlapEnds = Carbon::tomorrow()->setTime(17, 0, 0);

    $conflictResponse = $this->actingAs($this->user)
        ->postJson('/api/v1/appointments', [
            'contact_id' => $this->customer->id,
            'title' => 'Secondo appuntamento in sovrapposizione',
            'starts_at' => $overlapStarts->toIso8601String(),
            'ends_at' => $overlapEnds->toIso8601String(),
            'attendees' => [
                ['user_id' => $this->technician->id, 'role' => 'lead'],
            ],
        ])
        ->assertStatus(422);

    $conflictResponse->assertJsonValidationErrors(['attendees']);

    // Overriding conflict with allow_conflicts = true succeeds
    $this->actingAs($this->user)
        ->postJson('/api/v1/appointments', [
            'contact_id' => $this->customer->id,
            'title' => 'Secondo appuntamento forzato',
            'starts_at' => $overlapStarts->toIso8601String(),
            'ends_at' => $overlapEnds->toIso8601String(),
            'allow_conflicts' => true,
            'attendees' => [
                ['user_id' => $this->technician->id, 'role' => 'lead'],
            ],
        ])
        ->assertCreated();
});

test('calendar query retrieves events within the specified time window', function (): void {
    $day1 = Carbon::parse('2026-11-10 10:00:00');
    $day2 = Carbon::parse('2026-11-15 14:00:00');
    $outside = Carbon::parse('2026-12-01 09:00:00');

    Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0001',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'title' => 'Manutenzione 1',
        'starts_at' => $day1,
        'ends_at' => $day1->copy()->addHours(2),
    ]);

    Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0002',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'title' => 'Manutenzione 2',
        'starts_at' => $day2,
        'ends_at' => $day2->copy()->addHours(2),
    ]);

    Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0003',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'title' => 'Fuori periodo',
        'starts_at' => $outside,
        'ends_at' => $outside->copy()->addHours(2),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/appointments/calendar?start=2026-11-01T00:00:00Z&end=2026-11-30T23:59:59Z')
        ->assertOk();

    expect(count($response->json('data')))->toBe(2);
});

test('artisan can update and cancel an appointment with reason', function (): void {
    $appointment = Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0010',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'title' => 'Controllo fumi annuale',
        'starts_at' => Carbon::tomorrow()->setTime(10, 0),
        'ends_at' => Carbon::tomorrow()->setTime(11, 0),
    ]);

    // Update title
    $this->actingAs($this->user)
        ->putJson("/api/v1/appointments/{$appointment->id}", [
            'title' => 'Controllo fumi e analisi combustione',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Controllo fumi e analisi combustione');

    // Cancel appointment
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$appointment->id}/cancel", [
            'reason' => 'Cliente assente per emergenza personale',
        ])
        ->assertOk();

    $appointment->refresh();
    expect($appointment->isCancelled())->toBeTrue()
        ->and($appointment->cancellation_reason)->toBe('Cliente assente per emergenza personale');

    Event::assertDispatched(AppointmentCancelled::class);
});

test('completed appointment converts to official service report preserving intervention details', function (): void {
    $startsAt = Carbon::parse('2026-11-12 08:30:00');
    $endsAt = Carbon::parse('2026-11-12 11:30:00');

    $appointment = Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0020',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'ticket_id' => $this->ticket->id,
        'title' => 'Installazione contatore acqua e collaudo',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'address' => 'Via Nassa 28, 6900 Lugano',
        'technician_notes' => 'Montato contatore volumetrico DN25 matr. 88472.',
        'customer_notes' => 'Fatturare a nome Hotel Bellavista.',
        'is_portal_visible' => true,
    ]);

    AppointmentAttendee::create([
        'tenant_id' => $this->tenant->id,
        'appointment_id' => $appointment->id,
        'user_id' => $this->technician->id,
        'role' => 'lead',
        'is_organizer' => true,
        'status' => 'accepted',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$appointment->id}/convert-to-service-report")
        ->assertCreated();

    $reportId = $response->json('data.id');
    expect($reportId)->not->toBeNull();

    $appointment->refresh();
    expect($appointment->service_report_id)->toBe($reportId)
        ->and($appointment->isCompleted())->toBeTrue();

    // Verify service report content
    $report = ServiceReport::findOrFail($reportId);
    expect($report->contact_id)->toBe($this->customer->id)
        ->and($report->ticket_id)->toBe($this->ticket->id)
        ->and($report->technician_id)->toBe($this->technician->id)
        ->and((float) $report->total_hours)->toEqual(3.0)
        ->and($report->site_address)->toBe('Via Nassa 28, 6900 Lugano')
        ->and($report->customer_notes)->toBe('Fatturare a nome Hotel Bellavista.')
        ->and($report->internal_notes)->toContain('Da Appuntamento APT-2026-0020');

    Event::assertDispatched(AppointmentConvertedToServiceReport::class);

    // Double conversion blocked by policy
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$appointment->id}/convert-to-service-report")
        ->assertForbidden();
});

test('customer can view scheduled appointments on portal without seeing internal technician notes', function (): void {
    $startsAt = Carbon::tomorrow()->setTime(14, 30);
    $endsAt = Carbon::tomorrow()->setTime(16, 0);

    $appointment = Appointment::create([
        'tenant_id' => $this->tenant->id,
        'appointment_number' => 'APT-2026-0030',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $this->customer->id,
        'title' => 'Verifica periodica quadro elettrico',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'address' => 'Via Lungolago 14, Lugano',
        'customer_notes' => 'Arrivo previsto alle 14:30.',
        'technician_notes' => 'ATTENZIONE: magnetotermico difettoso sostituito temporaneamente.', // MUST NOT LEAK
        'is_portal_visible' => true,
    ]);

    AppointmentAttendee::create([
        'tenant_id' => $this->tenant->id,
        'appointment_id' => $appointment->id,
        'user_id' => $this->technician->id,
        'role' => 'lead',
    ]);

    $tokenService = app(PortalTokenService::class);
    $tokenData = $tokenService->createToken($this->customer, 'dashboard');
    $plaintext = $tokenData['token'];

    $response = $this->getJson("/api/v1/portal/appointments/{$plaintext}")->assertOk();

    expect(count($response->json('data')))->toBe(1)
        ->and($response->json('data.0.title'))->toBe('Verifica periodica quadro elettrico')
        ->and($response->json('data.0.customer_notes'))->toBe('Arrivo previsto alle 14:30.')
        ->and($response->json('data.0.technician_notes'))->toBeNull(); // Internal notes never exposed
});

test('strict multi-tenant isolation prevents cross-tenant access to appointments', function (): void {
    $otherTenant = Tenant::create([
        'name' => 'Altro Elettricista SA',
        'legal_name' => 'Altro Elettricista SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $otherUser = User::create([
        'name' => 'Luigi Neri',
        'email' => 'luigi@altro-elettricista.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $otherUser->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    app(TenantContext::class)->setTenant($otherTenant);

    $otherContact = Contact::create([
        'tenant_id' => $otherTenant->id,
        'company_name' => 'Condominio Sole',
    ]);

    $otherAppointment = Appointment::create([
        'tenant_id' => $otherTenant->id,
        'appointment_number' => 'APT-OTHER-0001',
        'status_id' => $this->scheduledStatus->id,
        'contact_id' => $otherContact->id,
        'title' => 'Riparazione citofoni',
        'starts_at' => Carbon::tomorrow()->setTime(9, 0),
        'ends_at' => Carbon::tomorrow()->setTime(10, 0),
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    // Main tenant user attempts to access other tenant's appointment
    $this->actingAs($this->user)
        ->getJson("/api/v1/appointments/{$otherAppointment->id}")
        ->assertNotFound();

    // Main tenant user attempts to update other tenant's appointment
    $this->actingAs($this->user)
        ->putJson("/api/v1/appointments/{$otherAppointment->id}", ['title' => 'Hacked'])
        ->assertNotFound();
});
