<?php

declare(strict_types=1);

use App\Models\PaymentTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AccountsPayableSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
    $this->invoice = apInvoice($this, $this->supplier, 'SK-1', '1000.00', 27, ['date' => now()->subDays(3)->toDateString()]);
    apSync($this);
    $this->itemId = apItem($this->invoice)->id;
});

function apSkonto(object $test, ?string $asOf = null): array
{
    $query = $asOf === null ? '' : '?as_of='.$asOf;

    return $test->getJson('/api/v1/accounts-payable/open-items/'.$test->itemId.$query)->assertOk()->json('data');
}

it('has no discount without terms', function (): void {
    expect(apSkonto($this))->toMatchArray([
        'skonto_eligible' => false, 'skonto_percentage' => null, 'skonto_deadline_date' => null, 'skonto_days_remaining' => null,
        'skonto_savings_amount' => '0.00', 'net_payable_with_skonto' => '1000.00', 'standard_amount' => '1000.00', 'skonto_terms_source' => null,
    ]);
});

it('reads a supplier agreement from the text of the payment clause and applies it to the open items', function (): void {
    $term = $this->postJson('/api/v1/accounts-payable/supplier-terms', [
        'supplier_id' => $this->supplier->id, 'clause' => '30 gg data fattura, 2% skonto entro 10 gg', 'reference' => 'CNT-2027-01',
    ])->assertCreated();

    expect($term->json('data'))->toMatchArray(['skonto_percentage' => '2.00', 'skonto_days' => 10, 'net_days' => 30, 'reference' => 'CNT-2027-01', 'is_active' => true]);

    // The invoice is 3 days old: the 10 day deadline is 7 days away.
    expect(apSkonto($this))->toMatchArray([
        'skonto_eligible' => true, 'skonto_percentage' => '2.00', 'skonto_deadline_date' => now()->addDays(7)->toDateString(), 'skonto_days_remaining' => 7,
        'skonto_savings_amount' => '20.00', 'net_payable_with_skonto' => '980.00', 'standard_amount' => '1000.00', 'skonto_terms_source' => 'supplier_agreement', 'skonto_net_days' => 30,
    ]);

    // After the deadline the discount is gone and the standard amount is due.
    expect(apSkonto($this, now()->addDays(8)->toDateString()))->toMatchArray([
        'skonto_eligible' => false, 'skonto_days_remaining' => -1, 'skonto_savings_amount' => '0.00', 'net_payable_with_skonto' => '1000.00',
    ]);

    // The list carries the same figures, and can be narrowed to the items that earn a discount.
    $list = $this->getJson('/api/v1/accounts-payable/open-items?skonto_eligible=1')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['skonto_savings_amount'])->toBe('20.00');
    $this->getJson('/api/v1/accounts-payable/open-items?skonto_eligible=1&as_of='.now()->addDays(20)->toDateString())->assertOk()->assertJsonCount(0, 'data');
});

it('falls back to the payment term of the supplier and lets a supplier agreement and a document override it', function (): void {
    $paymentTerm = PaymentTerm::query()->create(['tenant_id' => $this->tenant->id, 'code' => 'RD1-5', 'name' => '1% 5 days, net 30', 'days' => 30, 'discount_percent' => '1.00', 'discount_days' => 5]);
    $this->supplier->forceFill(['payment_term_id' => $paymentTerm->id])->save();

    expect(apSkonto($this))->toMatchArray(['skonto_eligible' => true, 'skonto_percentage' => '1.00', 'skonto_savings_amount' => '10.00', 'skonto_terms_source' => 'payment_term', 'skonto_net_days' => 30]);

    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 3, 'skonto_days' => 15, 'net_days' => 60])->assertCreated();
    expect(apSkonto($this))->toMatchArray(['skonto_percentage' => '3.00', 'skonto_savings_amount' => '30.00', 'skonto_terms_source' => 'supplier_agreement']);

    $this->putJson("/api/v1/accounts-payable/open-items/{$this->itemId}/skonto-terms", ['skonto_percentage' => 5, 'skonto_days' => 4])->assertOk();
    expect(apSkonto($this))->toMatchArray(['skonto_percentage' => '5.00', 'skonto_savings_amount' => '50.00', 'skonto_terms_source' => 'document', 'skonto_deadline_date' => now()->addDay()->toDateString()]);

    // Both fields null remove the override.
    $this->putJson("/api/v1/accounts-payable/open-items/{$this->itemId}/skonto-terms", ['skonto_percentage' => null, 'skonto_days' => null])->assertOk();
    expect(apSkonto($this)['skonto_terms_source'])->toBe('supplier_agreement');

    $this->putJson("/api/v1/accounts-payable/open-items/{$this->itemId}/skonto-terms", ['skonto_percentage' => 5])->assertUnprocessable()->assertJsonValidationErrors(['skonto_days']);
});

it('ignores an agreement that is inactive or outside its validity', function (): void {
    $term = $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10, 'valid_to' => now()->subDays(5)->toDateString()])->assertCreated();
    expect(apSkonto($this)['skonto_eligible'])->toBeFalse();

    $this->putJson('/api/v1/accounts-payable/supplier-terms/'.$term->json('data.id'), ['valid_to' => now()->addYear()->toDateString()])->assertOk();
    expect(apSkonto($this)['skonto_eligible'])->toBeTrue();

    $this->putJson('/api/v1/accounts-payable/supplier-terms/'.$term->json('data.id'), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    expect(apSkonto($this)['skonto_eligible'])->toBeFalse();
});

it('gives no discount on a partly paid invoice or a credit note', function (): void {
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10])->assertCreated();
    $credit = apCreditNote($this, $this->supplier, 'SK-NC', '100.00');
    apSync($this);

    $this->invoice->forceFill(['total_paid' => '100.00', 'payment_status' => 'partially_paid'])->save();

    expect(apSkonto($this)['skonto_eligible'])->toBeFalse();
    $this->getJson('/api/v1/accounts-payable/open-items/'.apItem($credit)->id)->assertOk()->assertJsonPath('data.skonto_eligible', false);
});

it('lists, changes and removes the agreements, and rejects invalid terms', function (): void {
    $created = $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 2, 'skonto_days' => 10])->assertCreated()->json('data');

    $this->getJson('/api/v1/accounts-payable/supplier-terms?supplier_id='.$this->supplier->id)->assertOk()->assertJsonCount(1, 'data');
    $this->putJson('/api/v1/accounts-payable/supplier-terms/'.$created['id'], ['clause' => '60 giorni, sconto 4% entro 20 giorni'])->assertOk()
        ->assertJsonPath('data.skonto_percentage', '4.00')->assertJsonPath('data.skonto_days', 20)->assertJsonPath('data.net_days', 60);
    $this->putJson('/api/v1/accounts-payable/supplier-terms/'.$created['id'], ['supplier_id' => $this->supplier->id])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id])->assertUnprocessable()->assertJsonValidationErrors(['clause']);
    $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'skonto_percentage' => 150, 'skonto_days' => 10])->assertUnprocessable()->assertJsonValidationErrors(['skonto_percentage']);
    $clause = $this->postJson('/api/v1/accounts-payable/supplier-terms', ['supplier_id' => $this->supplier->id, 'clause' => 'Pagamento a 30 gg'])->assertUnprocessable();
    expect(apErrorCode($clause))->toBe('clause_not_understood');

    $this->deleteJson('/api/v1/accounts-payable/supplier-terms/'.$created['id'])->assertOk();
    $this->getJson('/api/v1/accounts-payable/supplier-terms')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson('/api/v1/accounts-payable/supplier-terms/'.$created['id'])->assertNotFound();
});

it('reads a clause without saving it', function (): void {
    $this->postJson('/api/v1/accounts-payable/supplier-terms/parse', ['clause' => '2% Skonto innerhalb 10 Tagen, netto 30 Tage'])->assertOk()
        ->assertJsonPath('data', ['skonto_percentage' => '2.00', 'skonto_days' => 10, 'net_days' => 30]);

    $this->postJson('/api/v1/accounts-payable/supplier-terms/parse', ['clause' => 'netto 30 Tage'])->assertUnprocessable();
    $this->getJson('/api/v1/accounts-payable/supplier-terms')->assertOk()->assertJsonCount(0, 'data');
});
