<?php

declare(strict_types=1);

use App\Jobs\Appointments\QueueAppointmentRemindersJob;
use App\Jobs\Appointments\SendAppointmentReminderJob;
use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\AppointmentAttendee;
use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\AppointmentLookupSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new AppointmentLookupSeeder)->run();
    $this->status = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'appointment_status'))->where('code', 'scheduled')->first();

    $this->tenant = Tenant::create(['name' => 'Promemoria Srl', 'legal_name' => 'Promemoria Srl', 'country' => 'IT']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente Srl', 'email' => 'cliente@example.it', 'type' => 'customer', 'country' => 'IT']);
    $this->technician = User::create(['name' => 'Tecnico', 'email' => 'tecnico-'.Str::random(6).'@example.it', 'password' => bcrypt('secret123')]);

    $this->appointment = fn (string $number, string $startsIn, array $extra = []): Appointment => Appointment::create([
        'tenant_id' => $this->tenant->id, 'status_id' => $this->status->id, 'contact_id' => $this->contact->id,
        'appointment_number' => $number, 'title' => 'Manutenzione caldaia', 'address' => 'Via Roma 1, Milano',
        'starts_at' => now()->add($startsIn), 'ends_at' => now()->add($startsIn)->addHour(), ...$extra,
    ]);
});

test('the reminder is mailed to the customer contact and to the technicians, once', function (): void {
    Mail::fake();
    $appointment = ($this->appointment)('APP-100', '3 hours');
    AppointmentAttendee::create(['tenant_id' => $this->tenant->id, 'appointment_id' => $appointment->id, 'user_id' => $this->technician->id, 'role' => 'technician']);

    (new SendAppointmentReminderJob($appointment))->handle();
    (new SendAppointmentReminderJob($appointment))->handle();

    Mail::assertSent(AppointmentReminderMail::class, 2);
    Mail::assertSent(AppointmentReminderMail::class, fn (AppointmentReminderMail $mail): bool => $mail->hasTo('cliente@example.it'));
    Mail::assertSent(AppointmentReminderMail::class, fn (AppointmentReminderMail $mail): bool => $mail->hasTo($this->technician->email));
    expect($appointment->fresh()->reminder_sent_at)->not->toBeNull();

    $rendered = (new AppointmentReminderMail($appointment->fresh(['contact'])))->render();
    expect($rendered)->toContain('APP-100')->toContain('Via Roma 1, Milano');
});

test('the scheduler queues reminders only for appointments within the lead time without a reminder', function (): void {
    config(['appointments.reminder_lead_minutes' => 120]);
    Queue::fake();

    $due = ($this->appointment)('APP-201', '90 minutes');
    ($this->appointment)('APP-202', '5 hours');
    ($this->appointment)('APP-203', '30 minutes', ['reminder_sent_at' => now()]);
    ($this->appointment)('APP-204', '45 minutes', ['cancelled_at' => now()]);
    ($this->appointment)('APP-205', '-1 hour');

    (new QueueAppointmentRemindersJob((string) $this->tenant->id))->handle();

    Queue::assertPushed(SendAppointmentReminderJob::class, 1);
    Queue::assertPushed(SendAppointmentReminderJob::class, fn (SendAppointmentReminderJob $job): bool => (string) $job->appointment->id === (string) $due->id);
});

test('the tenant jobs that had no schedule are scheduled once per tenant on one server', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($event) => $event->description);

    foreach (['subscriptions:renewals', 'subscriptions:billing-run', 'subscriptions:revenue-recognition', 'inventory:expire-lots', 'appointments:reminders'] as $name) {
        expect($events->has($name))->toBeTrue("{$name} is not scheduled")
            ->and($events[$name]->onOneServer)->toBeTrue()
            ->and($events[$name]->withoutOverlapping)->toBeTrue();
    }

    expect($events['appointments:reminders']->expression)->toBe('*/15 * * * *');

    // Billing writes receivables for module invoices: it waits for the explicit switch.
    config(['subscription_billing.scheduled_billing' => false]);
    expect($events['subscriptions:billing-run']->filtersPass(app()))->toBeFalse();
    config(['subscription_billing.scheduled_billing' => true]);
    expect($events['subscriptions:billing-run']->filtersPass(app()))->toBeTrue();
});
