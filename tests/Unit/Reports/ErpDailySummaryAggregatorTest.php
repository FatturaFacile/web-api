<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\NotificationPreference;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantReportSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\DailySummary\ErpDailySummaryAggregator;
use App\Services\Reports\DailySummary\ScheduleMatcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('matches daily, weekly, monthly, and custom cron schedules in tenant local timezone and prevents duplicate period keys', function (): void {
    $matcher = app(ScheduleMatcherService::class);
    $tenant = Tenant::factory()->create(['country' => 'CH']);
    $user = User::factory()->create();

    $daily = NotificationPreference::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'frequency' => NotificationPreference::FREQUENCY_DAILY,
        'send_time' => '07:00',
    ]);

    $localDue = Carbon::parse('2026-11-26 07:00:00', 'Europe/Zurich');
    $localNotDue = Carbon::parse('2026-11-26 07:01:00', 'Europe/Zurich');

    expect($matcher->duePeriodKey($daily, $localDue))->toBe('2026-11-26')
        ->and($matcher->duePeriodKey($daily, $localNotDue))->toBeNull();

    $daily->update(['last_period_key' => '2026-11-26']);
    expect($matcher->duePeriodKey($daily, $localDue))->toBeNull();

    $weekly = NotificationPreference::factory()->make([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'frequency' => NotificationPreference::FREQUENCY_WEEKLY,
        'send_time' => '07:00',
        'day_of_week' => $localDue->dayOfWeek,
    ]);
    expect($matcher->duePeriodKey($weekly, $localDue))->toBe($localDue->format('o-\WW'));

    $monthly = NotificationPreference::factory()->make([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'frequency' => NotificationPreference::FREQUENCY_MONTHLY,
        'send_time' => '07:00',
        'day_of_month' => 26,
    ]);
    expect($matcher->duePeriodKey($monthly, $localDue))->toBe('2026-11');

    $cron = NotificationPreference::factory()->make([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'frequency' => NotificationPreference::FREQUENCY_CUSTOM_CRON,
        'custom_cron' => '0 7 * * *',
    ]);
    expect($matcher->duePeriodKey($cron, $localDue))->toBe('2026-11-26-07-00');
});

it('aggregates all 9 ERP sections with tenant isolation and generates intelligent insights', function (): void {
    $tenantA = Tenant::factory()->create(['country' => 'CH', 'default_currency' => 'CHF']);
    $tenantB = Tenant::factory()->create(['country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::factory()->create();

    $customerA = Contact::factory()->create(['tenant_id' => $tenantA->id, 'type' => 'customer']);
    $customerB = Contact::factory()->create(['tenant_id' => $tenantB->id, 'type' => 'customer']);

    Invoice::factory()->create([
        'tenant_id' => $tenantA->id,
        'contact_id' => $customerA->id,
        'date' => now()->subDays(45)->toDateString(),
        'due_date' => now()->subDays(35)->toDateString(),
        'total_net' => 1000,
        'total_tax' => 81,
        'total_gross' => 1081,
        'total_paid' => 0,
        'payment_status' => 'overdue',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $tenantB->id,
        'contact_id' => $customerB->id,
        'date' => now()->toDateString(),
        'total_net' => 9999,
        'total_tax' => 0,
        'total_gross' => 9999,
        'payment_status' => 'paid',
    ]);

    Payment::factory()->create([
        'tenant_id' => $tenantA->id,
        'amount' => 500,
        'paid_at' => now()->toDateString(),
    ]);

    Product::factory()->create([
        'tenant_id' => $tenantA->id,
        'track_inventory' => true,
        'is_active' => true,
        'min_stock' => 10,
        'cost_price' => 25,
    ]);

    Ticket::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $customerA->id,
        'status' => 'open',
        'priority' => 'urgent',
        'sla_due_at' => now()->addHours(4),
    ]);

    $settings = TenantReportSetting::factory()->create([
        'tenant_id' => $tenantA->id,
        'timezone' => 'Europe/Zurich',
        'enabled_modules' => NotificationPreference::MODULES,
    ]);

    $summary = app(ErpDailySummaryAggregator::class)->aggregate(
        $tenantA,
        $user,
        'de-CH',
        $settings,
        NotificationPreference::MODULES,
        useCache: false,
    );

    expect($summary['currency'])->toBe('CHF')
        ->and(array_keys($summary['sections']))->toBe(NotificationPreference::MODULES)
        ->and($summary['sections']['billing']['overdue_invoices_count'])->toBe(1)
        ->and($summary['sections']['billing']['overdue_invoices_total'])->toBe(1081.0)
        ->and($summary['sections']['support']['urgent_tickets_count'])->toBe(1)
        ->and($summary['sections']['support']['expiring_sla_tickets_count'])->toBe(1);

    $insightCodes = array_column($summary['sections']['insights']['items'], 'code');
    expect($insightCodes)->toContain('overdue_30d_alert')
        ->and($insightCodes)->toContain('sla_breach_alert');
});
