<?php

declare(strict_types=1);

use App\Models\BillableActivity;
use App\Models\FeeNote;
use App\Models\LegalCase;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-20 10:00:00');
    $this->tenant = verticalTenant();
    $this->clientA = verticalContact($this->tenant, 'Alfa Holding SA');
    $this->clientB = verticalContact($this->tenant, 'Beta Immobiliare SA');
    $this->partner = verticalMember($this->tenant, 'admin');

    app(TenantContext::class)->setTenant($this->tenant);
    $caseA = LegalCase::create(['client_id' => $this->clientA->id, 'case_number' => 'F-1', 'title' => 'Contabilità Alfa', 'status' => 'open', 'jurisdiction' => 'CH']);
    $caseB = LegalCase::create(['client_id' => $this->clientB->id, 'case_number' => 'F-2', 'title' => 'Contabilità Beta', 'status' => 'open', 'jurisdiction' => 'CH']);
    $log = fn (LegalCase $case, string $date, int $minutes, string $fee): BillableActivity => BillableActivity::create([
        'legal_case_id' => $case->id, 'user_id' => $this->partner->id, 'date' => $date, 'duration_minutes' => $minutes, 'hourly_rate' => '150', 'fee_amount' => $fee, 'is_billed' => false,
    ]);
    $this->activitiesA = [$log($caseA, '2026-11-03', 120, '300.00'), $log($caseA, '2026-11-04', 60, '150.00')];
    $this->activityB = $log($caseB, '2026-09-10', 90, '225.00');
    $this->activityBNovember = $log($caseB, '2026-11-05', 30, '75.00');

    verticalApi($this->partner, $this->tenant);
});

test('activities become a fee note once, with the rates entered by the user', function (): void {
    $control = $this->getJson('/api/v1/practice/activity-control?from=2026-09-01&to=2026-11-30&stale_days=30')->assertOk();
    expect($control->json('data.stale_unbilled'))->toBe(1)->and($control->json('data.rows'))->toHaveCount(2);

    $payload = ['client_id' => $this->clientA->id, 'activity_ids' => collect($this->activitiesA)->pluck('id')->all(), 'date' => '2026-11-20', 'vat_rate' => '8.1', 'ritenuta_rate' => '0'];
    $this->postJson('/api/v1/practice/fee-notes/from-activities', $payload)->assertUnprocessable()->assertJsonValidationErrors('cpa_rate');

    $note = $this->postJson('/api/v1/practice/fee-notes/from-activities', [...$payload, 'cpa_rate' => '4'])->assertCreated()
        ->assertJsonPath('data.taxable_fees', '450.00')->assertJsonPath('data.cassa_previdenza_amount', '18.00')
        ->assertJsonPath('data.vat_amount', '37.91')->assertJsonPath('data.net_payable', '505.91')->json('data');

    expect(BillableActivity::query()->where('fee_note_id', $note['id'])->where('is_billed', true)->count())->toBe(2);
    $this->getJson("/api/v1/practice/fee-notes/{$note['id']}/activities")->assertOk()->assertJsonCount(2, 'data');

    $this->postJson('/api/v1/practice/fee-notes/from-activities', [...$payload, 'cpa_rate' => '4'])->assertUnprocessable();
    $this->postJson('/api/v1/practice/fee-notes/from-activities', [...$payload, 'cpa_rate' => '4', 'activity_ids' => [$this->activityB->id], 'client_id' => $this->clientA->id])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'case of the client'));
});

test('the closing view spans every client and a period closes only when checked and billed', function (): void {
    $this->postJson('/api/v1/practice/fee-notes/from-activities', ['client_id' => $this->clientA->id, 'activity_ids' => collect($this->activitiesA)->pluck('id')->all(), 'date' => '2026-11-20', 'cpa_rate' => '0', 'vat_rate' => '0', 'ritenuta_rate' => '0'])->assertCreated();

    $overview = collect($this->getJson('/api/v1/practice/closing-overview?from=2026-11-01&to=2026-11-30')->assertOk()->json('data'))->keyBy('client_id');
    expect($overview[$this->clientA->id])->toMatchArray(['draft_fee_notes' => 1, 'draft_fee_notes_amount' => '450.00', 'unbilled_amount' => '0.00', 'closing_status' => 'not_started'])
        ->and($overview[$this->clientB->id])->toMatchArray(['unbilled_minutes' => 30, 'unbilled_amount' => '75.00']);

    $closing = $this->postJson('/api/v1/practice/closings', [
        'client_id' => $this->clientB->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
        'checklist' => [['key' => 'vat', 'label' => 'Rendiconto IVA'], ['key' => 'docs', 'label' => 'Documenti ricevuti']],
    ])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/practice/closings', ['client_id' => $this->clientB->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'checklist' => [['key' => 'x', 'label' => 'x']]])->assertUnprocessable();

    $this->postJson("/api/v1/practice/closings/{$closing}/close")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'checks'));
    $this->putJson("/api/v1/practice/closings/{$closing}/checklist", ['items' => [['key' => 'vat', 'done' => true], ['key' => 'docs', 'done' => true]]])->assertOk()->assertJsonPath('data.status', 'in_review');
    $this->postJson("/api/v1/practice/closings/{$closing}/close")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not billed'));
    $this->postJson("/api/v1/practice/closings/{$closing}/close", ['allow_unbilled' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/practice/closings/{$closing}/close", ['allow_unbilled' => true, 'reason' => 'Fatturato a gennaio'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson("/api/v1/practice/closings/{$closing}/checklist", ['items' => [['key' => 'vat', 'done' => false]]])->assertUnprocessable();

    verticalApi(verticalMember($this->tenant, 'accountant'), $this->tenant);
    $this->postJson("/api/v1/practice/closings/{$closing}/reopen")->assertForbidden();
    verticalApi($this->partner, $this->tenant);
    $this->postJson("/api/v1/practice/closings/{$closing}/reopen")->assertOk()->assertJsonPath('data.status', 'in_review');
});

test('other companies and ordinary members stay out of the practice data', function (): void {
    $closing = $this->postJson('/api/v1/practice/closings', ['client_id' => $this->clientA->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'checklist' => [['key' => 'x', 'label' => 'x']]])->assertCreated()->json('data.id');

    verticalApi(verticalMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/practice/closing-overview?from=2026-11-01&to=2026-11-30')->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/practice/closing-overview?from=2026-11-01&to=2026-11-30')->assertOk();
    $this->postJson("/api/v1/practice/closings/{$closing}/close")->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson('/api/v1/practice/closing-overview?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/practice/closings/{$closing}/close")->assertNotFound();
    $this->postJson('/api/v1/practice/fee-notes/from-activities', ['client_id' => $this->clientA->id, 'activity_ids' => [$this->activityB->id], 'date' => '2026-11-20', 'cpa_rate' => '0', 'vat_rate' => '0', 'ritenuta_rate' => '0'])
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    expect(FeeNote::query()->withoutGlobalScopes()->count())->toBe(0);
});
