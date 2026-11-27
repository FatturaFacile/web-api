<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\ForeignVatRefund\Models\ForeignVatAlertLog;
use Modules\ForeignVatRefund\Notifications\ForeignVatAlertNotification;
use Modules\ForeignVatRefund\Services\DeadlineAlertService;

require_once __DIR__.'/ForeignVatSupport.php';

uses(RefreshDatabase::class);

test('an alert goes to the finance roles at the 60 day mark and only once per level', function (): void {
    fvSetUp($this);
    Notification::fake();
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-02-10']);
    $service = app(DeadlineAlertService::class);

    // 60 days before 30 September 2027.
    $at60 = CarbonImmutable::parse('2027-08-01');
    expect($service->run($this->tenant, $at60))->toBe(1)->and($service->run($this->tenant, $at60))->toBe(0);
    Notification::assertSentTo($this->actor, ForeignVatAlertNotification::class);

    expect($service->run($this->tenant, CarbonImmutable::parse('2027-08-20')))->toBe(0)
        ->and($service->run($this->tenant, CarbonImmutable::parse('2027-08-31')))->toBe(1)
        ->and($service->run($this->tenant, CarbonImmutable::parse('2027-09-16')))->toBe(1)
        ->and(ForeignVatAlertLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->orderBy('level')->pluck('level')->all())->toBe([15, 30, 60]);
});

test('an expired deadline is notified once', function (): void {
    fvSetUp($this);
    Notification::fake();
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-02-10']);
    $service = app(DeadlineAlertService::class);

    expect($service->run($this->tenant, CarbonImmutable::parse('2027-10-02')))->toBe(1)->and($service->run($this->tenant, CarbonImmutable::parse('2027-10-03')))->toBe(0);
});

test('a missing representative for the Swiss procedure raises a mandate alert', function (): void {
    fvSetUp($this);
    Notification::fake();
    fvEntry($this, ['supplier_country_code' => 'CH', 'currency_code' => 'CHF', 'net_amount' => 20000, 'foreign_vat_rate' => 3.8, 'foreign_vat_amount' => 760, 'exchange_rate_to_base' => 1.05]);

    // 30 June 2027 is 60 days away on 1 May: the deadline alert and the mandate alert.
    expect(app(DeadlineAlertService::class)->run($this->tenant, CarbonImmutable::parse('2027-05-01')))->toBe(2);
});

test('a certificate about to expire is notified even without VAT waiting', function (): void {
    fvSetUp($this);
    Notification::fake();
    $this->postJson(FVR_API.'/mandates', ['country_code' => 'CH', 'representative_name' => 'Treuhand AG', 'mandate_start_date' => '2026-01-01', 'certificate_expiry_date' => '2027-08-15'])->assertCreated();

    expect(app(DeadlineAlertService::class)->run($this->tenant, CarbonImmutable::parse('2027-07-10')))->toBe(1);
});

test('roles outside the alert roles are not notified', function (): void {
    fvSetUp($this, 'IT', 'sales');
    Notification::fake();

    expect(app(DeadlineAlertService::class)->run($this->tenant, CarbonImmutable::parse('2027-08-01')))->toBe(0);
    Notification::assertNothingSent();
});

test('the scheduled command runs the alerts for the tenants', function (): void {
    fvSetUp($this);
    Notification::fake();
    fvEntry($this, ['supplier_country_code' => 'DE', 'document_date' => '2026-02-10']);
    $this->travelTo('2027-08-01 07:00:00');

    $this->artisan('foreign-vat:deadline-alerts', ['--tenant' => [(string) $this->tenant->id]])->assertSuccessful();

    Notification::assertSentTo($this->actor, ForeignVatAlertNotification::class);
});
