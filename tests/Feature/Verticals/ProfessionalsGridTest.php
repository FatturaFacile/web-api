<?php

declare(strict_types=1);

use App\Models\BillableActivity;
use App\Models\FeeNote;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PracticeManagement\Models\Retainer;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = verticalTenant();
    $this->clientA = verticalContact($this->tenant, 'Alfa Holding SA');
    $this->clientB = verticalContact($this->tenant, 'Beta Immobiliare SA');
    $partner = verticalMember($this->tenant, 'admin');

    app(TenantContext::class)->setTenant($this->tenant);
    $this->caseA = LegalCase::create(['client_id' => $this->clientA->id, 'case_number' => 'F-1', 'title' => 'Contabilita Alfa', 'status' => 'open', 'jurisdiction' => 'CH']);
    $this->caseB = LegalCase::create(['client_id' => $this->clientB->id, 'case_number' => 'F-2', 'title' => 'Contabilita Beta', 'status' => 'open', 'jurisdiction' => 'CH']);
    $log = fn (LegalCase $case, string $date, int $minutes, string $fee, bool $billed): BillableActivity => BillableActivity::create([
        'legal_case_id' => $case->id, 'user_id' => $partner->id, 'date' => $date, 'duration_minutes' => $minutes, 'hourly_rate' => '100.00', 'fee_amount' => $fee, 'description' => 'Work', 'is_billed' => $billed,
    ]);
    $log($this->caseA, '2026-11-02', 60, '100.00', false);
    $log($this->caseA, '2026-11-03', 30, '50.00', true);
    $log($this->caseB, '2026-10-03', 120, '200.00', false);

    foreach ([[$this->caseA, 'N-1', '1000.00'], [$this->caseB, 'N-2', '500.00']] as [$case, $number, $amount]) {
        FeeNote::create([
            'legal_case_id' => $case->id, 'client_id' => $case->client_id, 'note_number' => $number, 'date' => '2026-11-20',
            'taxable_fees' => $amount, 'cassa_previdenza_amount' => '0.00', 'ritenuta_acconto_amount' => '0.00', 'vat_amount' => '0.00', 'net_payable' => $amount, 'status' => 'draft',
        ]);
    }
    $retainer = fn (string $clientId, string $amount, string $applied): Retainer => Retainer::create([
        'client_id' => $clientId, 'received_on' => '2026-11-18', 'amount' => $amount, 'applied_amount' => $applied, 'status' => 'open',
    ]);
    $retainer($this->clientA->id, '1500.00', '500.00');
    $retainer($this->clientB->id, '300.00', '0.00');

    verticalApi($partner, $this->tenant);
});

test('the cases grid sorts and lists the calculated logged time, fees and client name', function (): void {
    $rows = $this->getJson('/api/v1/professionals/cases?sort_by=fee_total&sort_dir=desc')->assertOk()->json('data');
    expect(collect($rows)->pluck('case_number')->all())->toBe(['F-2', 'F-1']);

    $values = $this->getJson('/api/v1/professionals/cases?grid_values=client.name')->assertOk()->json('data');
    expect(collect($values)->pluck('value')->sort()->values()->all())->toBe(['Alfa Holding SA', 'Beta Immobiliare SA']);

    $filtered = $this->getJson('/api/v1/professionals/cases?filter[logged_minutes][]=90')->assertOk()->json('data');
    expect(collect($filtered)->pluck('case_number')->all())->toBe(['F-1']);
});

test('the time logs grid filters by period and billing status and groups by the calculated status', function (): void {
    $this->getJson('/api/v1/professionals/time-logs?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/professionals/time-logs?is_billed=false')->assertOk()->assertJsonCount(2, 'data');

    $values = collect($this->getJson('/api/v1/professionals/time-logs?grid_values=billing_status')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($values)->toBe(['billed' => 1, 'unbilled' => 2]);

    $rows = $this->getJson('/api/v1/professionals/time-logs?filter[legalCase.client.name][]=Beta Immobiliare SA')->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    $sorted = $this->getJson('/api/v1/professionals/time-logs?sort_by=billing_status&sort_dir=asc')->assertOk()->json('data.0.is_billed');
    expect($sorted)->toBeTrue();
});

test('the fee notes grid sorts by client name', function (): void {
    $rows = $this->getJson('/api/v1/professionals/fee-notes?sort_by=client.name&sort_dir=desc')->assertOk()->json('data');
    expect(collect($rows)->pluck('note_number')->all())->toBe(['N-2', 'N-1']);
});

test('the retainers grid is paginated and filters and sorts by the calculated balance', function (): void {
    $this->getJson('/api/v1/practice/retainers?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);

    $balances = collect($this->getJson('/api/v1/practice/retainers?sort_by=balance&sort_dir=asc')->assertOk()->json('data'))->pluck('amount')->all();
    expect($balances)->toBe(['300.00', '1500.00']);

    $values = collect($this->getJson('/api/v1/practice/retainers?grid_values=client.name')->assertOk()->json('data'))->pluck('value')->sort()->values()->all();
    expect($values)->toBe(['Alfa Holding SA', 'Beta Immobiliare SA']);
});
