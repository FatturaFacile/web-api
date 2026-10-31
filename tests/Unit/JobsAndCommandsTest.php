<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Subscriptions\NotifyUpcomingRenewals;
use App\Actions\Subscriptions\RunSubscriptionBilling;
use App\Jobs\Appointments\SendAppointmentReminderJob;
use App\Jobs\GenerateTenantBackupJob;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\LookupItem;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Services\Backup\TenantBackupService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\AppointmentLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

uses(RefreshDatabase::class);

test('send appointment reminder job handles cancelled or already notified appointments', function (): void {
    (new AppointmentLookupSeeder)->run();
    $scheduledStatus = LookupItem::whereHas('category', fn ($q) => $q->where('code', 'appointment_status'))
        ->where('code', 'scheduled')
        ->first();

    $tenant = Tenant::create(['name' => 'Reminder Tenant', 'legal_name' => 'Reminder Tenant SRL', 'country' => 'IT']);
    app(TenantContext::class)->setTenant($tenant);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'company_name' => 'Client SRL',
        'email' => 'client@example.com',
        'type' => 'customer',
        'country' => 'IT',
    ]);

    // 1. Cancelled appointment
    $cancelledAppointment = Appointment::create([
        'tenant_id' => $tenant->id,
        'status_id' => $scheduledStatus->id,
        'contact_id' => $contact->id,
        'appointment_number' => 'APP-001',
        'title' => 'Consulting',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'cancelled_at' => now(),
    ]);

    $job = new SendAppointmentReminderJob($cancelledAppointment);
    $job->handle();

    expect($cancelledAppointment->fresh()->reminder_sent_at)->toBeNull();

    // 2. Already sent reminder
    $alreadySentAppointment = Appointment::create([
        'tenant_id' => $tenant->id,
        'status_id' => $scheduledStatus->id,
        'contact_id' => $contact->id,
        'appointment_number' => 'APP-002',
        'title' => 'Consulting 2',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'reminder_sent_at' => now()->subHour(),
    ]);

    $job2 = new SendAppointmentReminderJob($alreadySentAppointment);
    $job2->handle();

    expect($alreadySentAppointment->fresh()->reminder_sent_at->isPast())->toBeTrue();

    // 3. Happy path: sends reminder and updates timestamp
    Log::shouldReceive('info')
        ->once()
        ->with(Mockery::pattern('/Appointment reminder dispatched for APP-003/'));

    $validAppointment = Appointment::create([
        'tenant_id' => $tenant->id,
        'status_id' => $scheduledStatus->id,
        'contact_id' => $contact->id,
        'appointment_number' => 'APP-003',
        'title' => 'Consulting 3',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);

    $job3 = new SendAppointmentReminderJob($validAppointment);
    $job3->handle();

    expect($validAppointment->fresh()->reminder_sent_at)->not->toBeNull();
});

test('tenant backup command validates arguments and dispatches jobs', function (): void {
    Queue::fake();

    // 1. Both --all and --tenant specified
    $exitCode = Artisan::call('tenants:backup', ['--all' => true, '--tenant' => '00000000-0000-0000-0000-000000000001']);
    expect($exitCode)->toBe(2);

    // 2. Neither specified
    $exitCode = Artisan::call('tenants:backup');
    expect($exitCode)->toBe(2);

    // 3. Tenant not found
    $exitCode = Artisan::call('tenants:backup', ['--tenant' => '00000000-0000-0000-0000-000000000999']);
    expect($exitCode)->toBe(1);

    // 4. Existing tenant
    $tenant = Tenant::create(['name' => 'Backup Tenant', 'legal_name' => 'Backup Tenant SRL', 'country' => 'IT']);

    $mockBackupService = Mockery::mock(TenantBackupService::class);
    $dummyBackup = new TenantBackup;
    $dummyBackup->id = (string) Str::uuid();

    $mockBackupService->shouldReceive('create')
        ->with(Mockery::on(fn ($t) => $t->id === $tenant->id))
        ->andReturn($dummyBackup);

    app()->instance(TenantBackupService::class, $mockBackupService);

    $exitCode = Artisan::call('tenants:backup', ['--tenant' => $tenant->id]);
    expect($exitCode)->toBe(0);

    Queue::assertPushed(GenerateTenantBackupJob::class, function ($job) use ($dummyBackup) {
        return $job->backupId === $dummyBackup->id;
    });
});

test('process subscriptions command iterates tenants and triggers billing and renewals', function (): void {
    $tenant = Tenant::create(['name' => 'Sub Tenant', 'legal_name' => 'Sub Tenant SRL', 'country' => 'IT']);

    $mockBilling = Mockery::mock(RunSubscriptionBilling::class);
    $mockRenewals = Mockery::mock(NotifyUpcomingRenewals::class);

    $mockRenewals->shouldReceive('execute')->andReturn(1);

    app()->instance(RunSubscriptionBilling::class, $mockBilling);
    app()->instance(NotifyUpcomingRenewals::class, $mockRenewals);

    $exitCode = Artisan::call('subscriptions:process');
    expect($exitCode)->toBe(0);
});
