<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ConstructionTrades\Models\SiteCost;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 10:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->user = verticalMember($this->tenant, 'admin');
    verticalApi($this->user, $this->tenant);

    $this->site = $this->postJson('/api/v1/construction/sites', ['client_id' => $this->customer->id, 'code' => 'C-1', 'name' => 'Casa Rossi', 'address' => 'Via Nassa 1, Lugano'])->assertCreated()->json('data.id');
    $this->subcontractor = $this->postJson('/api/v1/construction-trades/subcontractors', ['name' => 'Elettro Ticino SA', 'trade' => 'Impianti elettrici', 'contact_id' => $this->customer->id])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
});

test('subcontract certifications stay within the contract and feed the job costing of the bid', function (): void {
    $bid = $this->postJson('/api/v1/construction-trades/bids', ['contact_id' => $this->customer->id, 'title' => 'Casa Rossi', 'language' => 'it', 'currency' => 'CHF', 'tax_rate' => '8.1', 'construction_site_id' => $this->site])->assertCreated()->json('data.id');
    $payload = ['subcontractor_id' => $this->subcontractor, 'description' => 'Impianto elettrico', 'contract_amount' => '10000.00', 'bid_id' => $bid];

    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", [...$payload, 'contract_amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('contract_amount');
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", [...$payload, 'ends_on' => '2026-01-01', 'starts_on' => '2026-02-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    $subcontract = $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", $payload)->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');

    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/certifications", ['certified_on' => '2026-11-09', 'amount' => '6000.00', 'description' => 'SAL 1'])->assertCreated()->assertJsonPath('data.amount', '6000.00');
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/certifications", ['certified_on' => '2026-11-09', 'amount' => '4000.01', 'description' => 'Troppo'])->assertUnprocessable()
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'remaining contract amount of 4000.00'));
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/certifications", ['certified_on' => '2026-11-09', 'amount' => '-1', 'description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $show = $this->getJson("/api/v1/construction-trades/subcontracts/{$subcontract}")->assertOk()
        ->assertJsonPath('data.totals', ['contract_amount' => '10000.00', 'certified_amount' => '6000.00', 'remaining_amount' => '4000.00'])
        ->assertJsonCount(1, 'data.certifications');
    expect($show->json('data.subcontractor.name'))->toBe('Elettro Ticino SA')
        ->and(SiteCost::query()->where('bid_id', $bid)->where('cost_type', 'other')->sum('amount'))->toEqual(6000);
    $this->getJson("/api/v1/construction-trades/bids/{$bid}/budget-control")->assertOk()->assertJsonPath('data.unassigned_costs', '6000.00');
    $this->getJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts")->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/certifications", ['certified_on' => '2026-11-09', 'amount' => '1.00', 'description' => 'Dopo'])->assertUnprocessable();
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/status", ['status' => 'cancelled'])->assertUnprocessable();

    $this->putJson("/api/v1/construction-trades/subcontractors/{$this->subcontractor}", ['status' => 'suspended'])->assertOk();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", $payload)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'suspended'));
});

test('a bid of another site cannot take the subcontract', function (): void {
    $otherSite = $this->postJson('/api/v1/construction/sites', ['client_id' => $this->customer->id, 'code' => 'C-2', 'name' => 'Casa Verdi', 'address' => 'Via Cantonale 2'])->assertCreated()->json('data.id');
    $bid = $this->postJson('/api/v1/construction-trades/bids', ['contact_id' => $this->customer->id, 'title' => 'Verdi', 'language' => 'it', 'currency' => 'CHF', 'tax_rate' => '8.1', 'construction_site_id' => $otherSite])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", ['subcontractor_id' => $this->subcontractor, 'description' => 'X', 'contract_amount' => '100.00', 'bid_id' => $bid])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'another construction site'));
});

test('the diary is written once per day and documents report their expiry', function (): void {
    $entry = ['entry_date' => '2026-11-09', 'weather' => 'Soleggiato', 'workers_present' => 6, 'work_performed' => 'Posa tubazioni', 'incidents' => null];
    $id = $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", $entry)->assertCreated()->assertJsonPath('data.recorded_by', $this->user->id)->json('data.id');
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", $entry)->assertUnprocessable();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", [...$entry, 'entry_date' => '2026-11-10', 'workers_present' => -1])->assertUnprocessable()->assertJsonValidationErrors('workers_present');
    $this->putJson("/api/v1/construction-trades/diary/{$id}", ['entry_date' => '2026-11-12'])->assertUnprocessable()->assertJsonValidationErrors('entry_date');
    $this->putJson("/api/v1/construction-trades/diary/{$id}", ['workers_present' => 7, 'incidents' => 'Nessuno'])->assertOk()->assertJsonPath('data.workers_present', 7);
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", [...$entry, 'entry_date' => '2026-11-08'])->assertCreated();
    $this->getJson("/api/v1/construction-trades/sites/{$this->site}/diary")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.entry_date', fn (string $date): bool => str_starts_with($date, '2026-11-09'));

    $base = ['kind' => 'insurance', 'title' => 'RC subappaltatore', 'subcontractor_id' => $this->subcontractor];
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'expires_on' => '2026-11-01'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'title' => 'Piano sicurezza', 'kind' => 'safety_plan', 'subcontractor_id' => null, 'expires_on' => '2026-11-30'])->assertCreated();
    $far = $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'title' => 'Formazione', 'kind' => 'training_certificate', 'expires_on' => '2027-06-01'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'title' => 'Permesso', 'kind' => 'permit'])->assertCreated();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'kind' => 'bogus'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", [...$base, 'dms_document_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('dms_document_id');

    $report = $this->getJson('/api/v1/construction-trades/site-documents/expiring?within_days=30')->assertOk()->assertJsonCount(2, 'data');
    expect($report->json('data.0'))->toMatchArray(['title' => 'RC subappaltatore', 'expired' => true, 'days_left' => -8, 'subcontractor_name' => 'Elettro Ticino SA'])
        ->and($report->json('data.1'))->toMatchArray(['title' => 'Piano sicurezza', 'expired' => false, 'days_left' => 21]);
    $this->getJson('/api/v1/construction-trades/site-documents/expiring')->assertUnprocessable()->assertJsonValidationErrors('within_days');
    $this->getJson("/api/v1/construction-trades/sites/{$this->site}/documents")->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.3.title', 'Permesso');
    $this->deleteJson("/api/v1/construction-trades/site-documents/{$far}")->assertNoContent();
    $this->getJson('/api/v1/construction-trades/site-documents/expiring?within_days=400')->assertOk()->assertJsonCount(2, 'data');
});

test('read-only roles and other companies stay out of the site management', function (): void {
    $subcontract = $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", ['subcontractor_id' => $this->subcontractor, 'description' => 'X', 'contract_amount' => '100.00'])->assertCreated()->json('data.id');
    $document = $this->postJson("/api/v1/construction-trades/sites/{$this->site}/documents", ['kind' => 'permit', 'title' => 'Permesso'])->assertCreated()->json('data.id');

    verticalApi(verticalMember($this->tenant, 'technician'), $this->tenant);
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", ['entry_date' => '2026-11-09', 'workers_present' => 1, 'work_performed' => 'x'])->assertCreated();

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/construction-trades/subcontracts/{$subcontract}")->assertOk();
    $this->postJson('/api/v1/construction-trades/subcontractors', ['name' => 'X'])->assertForbidden();
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/certifications", ['certified_on' => '2026-11-09', 'amount' => '1.00', 'description' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/diary", ['entry_date' => '2026-11-10', 'workers_present' => 1, 'work_performed' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/construction-trades/site-documents/{$document}")->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/construction-trades/subcontracts/{$subcontract}")->assertNotFound();
    $this->getJson("/api/v1/construction-trades/sites/{$this->site}/diary")->assertNotFound();
    $this->getJson("/api/v1/construction-trades/sites/{$this->site}/documents")->assertNotFound();
    $this->putJson("/api/v1/construction-trades/subcontractors/{$this->subcontractor}", ['name' => 'Y'])->assertNotFound();
    $this->deleteJson("/api/v1/construction-trades/site-documents/{$document}")->assertNotFound();
    $this->postJson("/api/v1/construction-trades/subcontracts/{$subcontract}/status", ['status' => 'cancelled'])->assertNotFound();
    $this->postJson("/api/v1/construction-trades/sites/{$this->site}/subcontracts", ['subcontractor_id' => $this->subcontractor, 'description' => 'X', 'contract_amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('subcontractor_id');
    $this->getJson('/api/v1/construction-trades/subcontractors')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/construction-trades/site-documents/expiring?within_days=3650')->assertOk()->assertJsonCount(0, 'data');
});
