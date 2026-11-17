<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ServiceManagement\Exceptions\HourBankException;
use Modules\ServiceManagement\Jobs\SendResidualHoursReportJob;
use Modules\ServiceManagement\Mail\ResidualHoursReportMail;
use Modules\ServiceManagement\Models\ContractHourTransaction;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Services\HourBankService;
use Modules\ServiceManagement\Services\ResidualHoursReportService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 15)->startOfDay());

    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $user = User::create(['name' => 'Agent', 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->customer = Contact::factory()->create([
        'tenant_id' => $this->tenant->id,
        'country' => 'IT',
        'company_name' => 'Rossi SRL',
        'email' => 'rossi@example.test',
    ]);
});

function mcRegisterAsset(object $test, string $serial, array $extra = []): string
{
    return $test->postJson('/api/v1/esm/equipment-passports', array_merge([
        'serial_number' => $serial,
        'current_owner_customer_id' => $test->customer->id,
        'delivery_date' => '2026-01-10',
        'commissioning_date' => '2026-01-20',
        'installation_site' => 'Via Roma 1, Milano',
    ], $extra))->assertCreated()->json('data.id');
}

function mcCreateContract(object $test, array $extra = []): array
{
    return $test->postJson('/api/v1/esm/service-contracts', array_merge([
        'customer_id' => $test->customer->id,
        'contract_number' => 'MC-001',
        'title' => 'Manutenzione annuale',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'billing_recurrence' => 'quarterly',
        'included_hours' => 10,
    ], $extra))->assertCreated()->json('data');
}

test('installed asset exposes status, commissioning date, site and computed warranty status', function () {
    $id = mcRegisterAsset($this, 'SN-197-1', ['asset_status' => 'under_repair']);

    $this->getJson("/api/v1/esm/equipment-passports/{$id}")
        ->assertOk()
        ->assertJsonPath('data.asset_status', 'under_repair')
        ->assertJsonPath('data.commissioning_date', '2026-01-20')
        ->assertJsonPath('data.installation_site', 'Via Roma 1, Milano')
        ->assertJsonPath('data.warranty_status', 'active');

    $this->getJson('/api/v1/esm/equipment-passports?asset_status=active')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/esm/equipment-passports?asset_status=under_repair')->assertOk()->assertJsonCount(1, 'data');
});

test('contract is created with covered assets and rejects assets of another tenant', function () {
    $own = mcRegisterAsset($this, 'SN-197-OWN');

    $contract = mcCreateContract($this, ['asset_ids' => [$own]]);
    expect($contract['remaining_hours'])->toBe('10.00')
        ->and($contract['billing_recurrence'])->toBe('quarterly');

    $this->getJson('/api/v1/esm/service-contracts/'.$contract['id'])->assertOk();

    $otherTenant = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    app(TenantContext::class)->setTenant($otherTenant);
    $foreign = SerializedEquipmentPassport::factory()->create(['tenant_id' => $otherTenant->id]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->postJson('/api/v1/esm/service-contracts', [
        'customer_id' => $this->customer->id,
        'contract_number' => 'MC-002',
        'title' => 'X',
        'start_date' => '2026-01-01',
        'asset_ids' => [$foreign->id],
    ])->assertUnprocessable();
});

test('top-up, atomic deduction and ledger keep the balance consistent and never negative', function () {
    $contract = ServiceContract::find(mcCreateContract($this, ['status' => 'active'])['id']);
    $contract->update(['status' => 'active']);
    Queue::fake();

    $bank = app(HourBankService::class);
    $bank->topUp($contract, '10.00', 'Initial');
    $entry = $bank->deduct($contract->fresh(), '2.50', ['reference' => 'WO-1', 'technician_name' => 'Luigi']);

    expect($entry->hours)->toBe('-2.50')
        ->and($entry->balance_after)->toBe('17.50')
        ->and($contract->fresh()->remainingHours())->toBe('17.50');

    expect(fn () => $bank->deduct($contract->fresh(), '100.00'))->toThrow(HourBankException::class);
    expect($contract->fresh()->remainingHours())->toBe('17.50');

    expect(fn () => $bank->adjust($contract->fresh(), '-999.00'))->toThrow(HourBankException::class);

    expect(fn () => $entry->update(['hours' => '0.00']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    $sum = ContractHourTransaction::query()->where('service_contract_id', $contract->id)->sum('hours');
    expect(number_format((float) $sum, 2, '.', ''))->toBe('17.50');

    $this->getJson("/api/v1/esm/service-contracts/{$contract->id}/ledger")->assertOk()->assertJsonCount(3, 'data');
});

test('deduction is idempotent per work order and queues the residual report', function () {
    $contract = ServiceContract::find(mcCreateContract($this)['id']);
    $contract->update(['status' => 'active']);
    Queue::fake();

    $bank = app(HourBankService::class);
    $bank->deduct($contract, '1.00', ['work_order_id' => '0195a6c0-0000-7000-8000-000000000001']);
    $bank->deduct($contract->fresh(), '1.00', ['work_order_id' => '0195a6c0-0000-7000-8000-000000000001']);

    expect($contract->fresh()->consumed_hours)->toBe('1.00');
    Queue::assertPushed(SendResidualHoursReportJob::class, 1);
});

test('inactive contract cannot be debited', function () {
    $contract = ServiceContract::find(mcCreateContract($this)['id']); // draft

    expect(fn () => app(HourBankService::class)->deduct($contract, '1.00'))->toThrow(HourBankException::class);
});

test('manual top-up endpoint and report trigger work, report mail carries the PDF', function () {
    $contract = mcCreateContract($this);
    Queue::fake();

    $this->postJson("/api/v1/esm/service-contracts/{$contract['id']}/hours/top-up", ['hours' => '5', 'description' => 'Renewal'])
        ->assertCreated()
        ->assertJsonPath('contract.included_hours', '15.00');

    $this->postJson("/api/v1/esm/service-contracts/{$contract['id']}/residual-report")->assertStatus(202);
    Queue::assertPushed(SendResidualHoursReportJob::class);

    Mail::fake();
    (new SendResidualHoursReportJob($contract['id'], $this->tenant->id))->handle(app(TenantContext::class), app(ResidualHoursReportService::class));

    Mail::assertSent(ResidualHoursReportMail::class, fn (ResidualHoursReportMail $mail): bool => $mail->hasTo('rossi@example.test') && count($mail->attachments()) === 1);
    expect(ServiceContract::find($contract['id'])->last_residual_report_at)->not->toBeNull();
});

test('the residual hours mail and the PDF statement views render with the contract data', function () {
    $contract = ServiceContract::find(mcCreateContract($this)['id']);
    $service = app(ResidualHoursReportService::class);
    $data = $service->viewData($contract, Contact::query()->find($contract->customer_id), 'en');

    $mailHtml = (new ResidualHoursReportMail('Residual hours', $data, '%PDF', 'residual.pdf'))->render();
    $statementHtml = view('documents.residual-hours-statement', $data)->render();

    expect($mailHtml)->toContain($contract->contract_number)
        ->and($statementHtml)->toContain($contract->contract_number)
        ->and($statementHtml)->toContain($data['customerName']);
});
