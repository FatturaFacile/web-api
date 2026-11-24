<?php

declare(strict_types=1);

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
    $this->caseA = LegalCase::create(['client_id' => $this->clientA->id, 'case_number' => 'F-1', 'title' => 'Contabilita Alfa', 'status' => 'open', 'jurisdiction' => 'CH']);
    $this->caseA2 = LegalCase::create(['client_id' => $this->clientA->id, 'case_number' => 'F-3', 'title' => 'Consulenza Alfa', 'status' => 'open', 'jurisdiction' => 'CH']);
    $caseB = LegalCase::create(['client_id' => $this->clientB->id, 'case_number' => 'F-2', 'title' => 'Contabilita Beta', 'status' => 'open', 'jurisdiction' => 'CH']);
    $note = fn (LegalCase $case, string $number): FeeNote => FeeNote::create([
        'legal_case_id' => $case->id, 'client_id' => $case->client_id, 'note_number' => $number, 'date' => '2026-11-20',
        'taxable_fees' => '1000.00', 'cassa_previdenza_amount' => '0.00', 'ritenuta_acconto_amount' => '0.00', 'vat_amount' => '0.00', 'net_payable' => '1000.00', 'status' => 'draft',
    ]);
    $this->noteA = $note($this->caseA, 'N-1');
    $this->noteA2 = $note($this->caseA2, 'N-3');
    $this->noteB = $note($caseB, 'N-2');

    verticalApi($this->partner, $this->tenant);
    $this->retainer = fn (array $overrides = []) => $this->postJson('/api/v1/practice/retainers', ['client_id' => $this->clientA->id, 'received_on' => '2026-11-18', 'amount' => '1500.00', 'reference' => 'Bonifico 123', ...$overrides]);
});

test('a retainer is applied to the fee notes of its client within what is left and what is asked', function (): void {
    $id = ($this->retainer)()->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.applied_amount', '0.00')->json('data.id');

    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteB->id, 'amount' => '100.00'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'another client'));
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '1500.01'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'only 1500.00 left'));

    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '900.00'])->assertCreated()->assertJsonPath('data.applied_on', fn (string $date): bool => str_starts_with($date, '2026-11-20'));
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '200.00'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'asks only 100.00 more'));
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '100.00'])->assertCreated();
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA2->id, 'amount' => '500.00'])->assertCreated();

    $this->getJson("/api/v1/practice/fee-notes/{$this->noteA->id}/retainers")->assertOk()
        ->assertJsonPath('data.retainers_applied', '1000.00')->assertJsonPath('data.outstanding', '0.00')->assertJsonCount(2, 'data.applications');
    $this->getJson('/api/v1/practice/retainers?status=exhausted')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.applied_amount', '1500.00');
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA2->id, 'amount' => '1.00'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'open retainer'));
    $this->postJson("/api/v1/practice/retainers/{$id}/void")->assertUnprocessable();

    ($this->retainer)(['amount' => '300.00'])->assertCreated();
    $balances = collect($this->getJson('/api/v1/practice/retainers/balances')->assertOk()->json('data'))->keyBy('client_id');
    expect($balances[$this->clientA->id])->toMatchArray(['received' => '1800.00', 'applied' => '1500.00', 'available' => '300.00']);
});

test('a case retainer applies only to that case and a mistaken retainer can be voided', function (): void {
    ($this->retainer)(['legal_case_id' => LegalCase::query()->withoutGlobalScopes()->where('case_number', 'F-2')->value('id')])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'another client'));

    $caseId = $this->caseA->id;
    $id = ($this->retainer)(['legal_case_id' => $caseId])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA2->id, 'amount' => '100.00'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'for a case'));
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '100.00'])->assertCreated();

    $other = ($this->retainer)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/practice/retainers/{$other}/void")->assertOk()->assertJsonPath('data.status', 'void');
    $this->postJson("/api/v1/practice/retainers/{$other}/void")->assertUnprocessable();
    $this->postJson("/api/v1/practice/retainers/{$other}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '1.00'])->assertUnprocessable();
    ($this->retainer)(['amount' => '12.345'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    ($this->retainer)(['client_id' => null])->assertUnprocessable()->assertJsonValidationErrors('client_id');

    FeeNote::query()->whereKey($this->noteA2->id)->update(['status' => 'paid']);
    $fresh = ($this->retainer)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/practice/retainers/{$fresh}/apply", ['fee_note_id' => $this->noteA2->id, 'amount' => '1.00'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already paid'));
});

test('ordinary members, read-only roles and other companies stay out of the retainers', function (): void {
    $id = ($this->retainer)()->assertCreated()->json('data.id');

    verticalApi(verticalMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/practice/retainers')->assertForbidden();

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/practice/retainers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/practice/retainers/balances')->assertOk();
    ($this->retainer)()->assertForbidden();
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '1.00'])->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson('/api/v1/practice/retainers')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/practice/retainers/{$id}/void")->assertNotFound();
    $this->getJson("/api/v1/practice/fee-notes/{$this->noteA->id}/retainers")->assertNotFound();
    $this->postJson("/api/v1/practice/retainers/{$id}/apply", ['fee_note_id' => $this->noteA->id, 'amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('fee_note_id');
    ($this->retainer)()->assertUnprocessable()->assertJsonValidationErrors('client_id');
});
