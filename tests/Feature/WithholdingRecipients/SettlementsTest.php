<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\WithholdingRecipients\Models\RecipientSettlement;

require_once __DIR__.'/WithholdingRecipientsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    wrSetUp($this);
});

it('posts an author royalty at the payment date and freezes every figure', function (): void {
    $author = wrRecipient($this, 'author_royalty');

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15', 'service_date' => '2026-06-01', 'payment_reference' => 'BON-77'])->assertCreated()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.regime', 'author_royalty')
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.flat_rate_deduction', '400.00')
        ->assertJsonPath('data.taxable_base', '600.00')
        ->assertJsonPath('data.irpef_withholding', '120.00')
        ->assertJsonPath('data.net_payable', '880.00')
        ->assertJsonPath('data.non_taxable_amount', '400.00')
        ->assertJsonPath('data.non_taxable_code', '22')
        ->assertJsonPath('data.cu_causal', 'B')
        ->assertJsonPath('data.tax_year', 2026)
        ->assertJsonPath('data.accrual_month', '2026-06')
        ->assertJsonPath('data.f24_due_date', '2026-07-16')
        ->assertJsonPath('data.f24_lines.0.tax_code', '1040')
        ->assertJsonPath('data.f24_lines.0.amount', '120.00')
        ->assertJsonPath('data.f24_lines.0.reference_month', '06')
        ->assertJsonPath('data.uniemens_required', false)
        ->assertJsonPath('data.payment_reference', 'BON-77')
        ->assertJsonPath('data.document_number', 'CP-2026-00001');

    expect(DB::table('fiscal_recipient_settlements')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 500, 'payment_date' => '2026-06-20'])->assertCreated()->assertJsonPath('data.document_number', 'CP-2026-00002');
});

it('does not recompute a posted settlement when the profile changes later', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $id = $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-15'])->assertCreated()->json('data.id');

    $this->putJson(WR_API."/recipients/{$author->id}", ['birth_date' => '1970-01-01'])->assertOk();

    $this->getJson(WR_API."/settlements/{$id}")->assertOk()->assertJsonPath('data.flat_rate_deduction', '400.00')->assertJsonPath('data.recipient_name', 'Giulia Autrice');
});

it('accrues the tax in the payment month and applies the extended cash rule until 12 January', function (): void {
    $author = wrRecipient($this, 'author_royalty');

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2027-01-12', 'service_date' => '2026-12-20'])->assertCreated()
        ->assertJsonPath('data.tax_year', 2026)
        ->assertJsonPath('data.accrual_month', '2027-01')
        ->assertJsonPath('data.f24_due_date', '2027-02-16')
        ->assertJsonPath('data.f24_lines.0.reference_year', 2026);

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2027-01-13', 'service_date' => '2026-12-20'])->assertCreated()
        ->assertJsonPath('data.tax_year', 2027);
});

it('refuses a service later than the payment and an inactive recipient', function (): void {
    $author = wrRecipient($this, 'author_royalty');

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 100, 'payment_date' => '2026-06-15', 'service_date' => '2026-06-16'])->assertStatus(422)->assertJsonPath('code', 'service_after_payment');

    $this->putJson(WR_API."/recipients/{$author->id}", ['is_active' => false])->assertOk();
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 100, 'payment_date' => '2026-06-15'])->assertStatus(422)->assertJsonPath('code', 'recipient_inactive');

    expect(RecipientSettlement::query()->count())->toBe(0);

    $this->postJson(WR_API.'/settlements', [])->assertStatus(422)->assertJsonValidationErrors(['recipient_profile_id', 'gross_amount', 'payment_date']);
});

it('blocks the payment of occasional work without a sent INL communication that covers the service', function (): void {
    $worker = wrRecipient($this, 'occasional_worker');
    $body = ['recipient_profile_id' => $worker->id, 'gross_amount' => 800, 'payment_date' => '2026-06-20', 'service_date' => '2026-06-10'];

    $this->postJson(WR_API.'/settlements', $body)->assertStatus(422)->assertJsonPath('code', 'inl_notification_missing');

    // a draft is not a sent communication
    $draft = $this->postJson(WR_API."/recipients/{$worker->id}/inl-notifications", ['work_start_date' => '2026-06-01', 'work_end_date' => '2026-06-30', 'activity_description' => 'Docenza'])->assertCreated()->json('data.id');
    $this->postJson(WR_API.'/settlements', $body)->assertStatus(422)->assertJsonPath('code', 'inl_notification_missing');

    // sent after the work started: late
    $this->postJson(WR_API."/inl-notifications/{$draft}/mark-sent", ['sent_at' => '2026-06-05 09:00:00'])->assertOk();
    $this->postJson(WR_API.'/settlements', $body)->assertStatus(422)->assertJsonPath('code', 'inl_notification_late');

    // a communication for another period does not help: still late for June, expired for a service in August
    wrInlSent($this, $worker, '2026-05-01', '2026-05-31');
    $this->postJson(WR_API.'/settlements', $body)->assertStatus(422)->assertJsonPath('code', 'inl_notification_late');
    $this->postJson(WR_API.'/settlements', ['service_date' => '2026-08-10', 'payment_date' => '2026-08-12'] + $body)->assertStatus(422)->assertJsonPath('code', 'inl_notification_expired');

    // a timely communication unlocks the payment
    $inlId = wrInlSent($this, $worker, '2026-06-08', '2026-06-30');
    $this->postJson(WR_API.'/settlements', $body)->assertCreated()
        ->assertJsonPath('data.inl_notification_id', $inlId)
        ->assertJsonPath('data.stamp_duty', '2.00')
        ->assertJsonPath('data.irpef_withholding', '160.00')
        ->assertJsonPath('data.net_payable', '638.00');
});

it('does not require the INL communication for other regimes', function (): void {
    $sports = wrRecipient($this, 'amateur_sports');

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $sports->id, 'gross_amount' => 1500, 'payment_date' => '2026-06-15'])->assertCreated()
        ->assertJsonPath('data.inl_notification_id', null)
        ->assertJsonPath('data.net_payable', '1500.00')
        ->assertJsonPath('data.f24_lines', []);
});

it('accumulates the year to date: the occasional worker pays INPS only above EUR 5,000, split 1/3 and 2/3', function (): void {
    $worker = wrRecipient($this, 'occasional_worker', ['third_party_ytd_income' => 1000, 'third_party_income_year' => 2026]);
    wrInlSent($this, $worker);
    $base = ['recipient_profile_id' => $worker->id, 'service_date' => '2026-06-10'];

    $this->postJson(WR_API.'/settlements', $base + ['gross_amount' => 3000, 'payment_date' => '2026-06-15'])->assertCreated()
        ->assertJsonPath('data.inps_total', '0.00')
        ->assertJsonPath('data.uniemens_required', false)
        ->assertJsonPath('data.f24_lines.0.tax_code', '1040');

    // 1,000 certified + 3,000 + 2,500 = 6,500: 1,500 above the threshold
    $second = $this->postJson(WR_API.'/settlements', $base + ['gross_amount' => 2500, 'payment_date' => '2026-06-25'])->assertCreated()
        ->assertJsonPath('data.inps_base', '1500.00')
        ->assertJsonPath('data.inps_total', '505.80')
        ->assertJsonPath('data.inps_worker', '168.60')
        ->assertJsonPath('data.inps_company', '337.20')
        ->assertJsonPath('data.irpef_withholding', '500.00')
        ->assertJsonPath('data.net_payable', '1829.40') // 2,500 - 500 - 168.60 - 2.00
        ->assertJsonPath('data.company_cost', '2837.20')
        ->assertJsonPath('data.uniemens_required', true)
        ->assertJsonPath('data.f24_lines.1.tax_code', 'C10')
        ->assertJsonPath('data.f24_lines.1.amount', '505.80')
        ->assertJsonPath('data.warnings.0', 'inps_threshold_exceeded');

    expect($second->json('data.breakdown.thresholds.0.key'))->toBe('occasional_5000');
});

it('applies the three sports tiers across successive payments', function (): void {
    $sports = wrRecipient($this, 'amateur_sports');
    $post = fn (int $gross, string $date) => $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $sports->id, 'gross_amount' => $gross, 'payment_date' => $date]);

    $post(4000, '2026-02-10')->assertCreated()->assertJsonPath('data.irpef_withholding', '0.00')->assertJsonPath('data.inps_total', '0.00');
    // 4,000 -> 9,000: 4,000 above 5,000 on a 50% base
    $post(5000, '2026-03-10')->assertCreated()->assertJsonPath('data.inps_base', '2000.00')->assertJsonPath('data.irpef_withholding', '0.00')->assertJsonPath('data.uniemens_required', true);
    // 9,000 -> 17,000: 2,000 above 15,000 at 23%
    $post(8000, '2026-04-10')->assertCreated()->assertJsonPath('data.taxable_base', '2000.00')->assertJsonPath('data.irpef_withholding', '460.00')->assertJsonPath('data.non_taxable_amount', '6000.00')->assertJsonPath('data.non_taxable_code', '24');
});

it('accrues Enasarco up to the ceiling and FIRR on brackets across the year', function (): void {
    $agent = wrRecipient($this, 'agent_25bis');
    $post = fn (int $gross, string $date) => $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $agent->id, 'gross_amount' => $gross, 'payment_date' => $date]);

    $post(40000, '2026-03-10')->assertCreated()
        ->assertJsonPath('data.enasarco_base', '40000.00')
        ->assertJsonPath('data.enasarco_agent', '3500.00')
        ->assertJsonPath('data.irpef_withholding', '4600.00')
        ->assertJsonPath('data.f24_lines.0.tax_code', '1038')
        ->assertJsonPath('data.cu_causal', 'Q')
        // 12,500 at 4% + 7,500 at 2% + 12,000 at 1% + 8,000 at 0%
        ->assertJsonPath('data.firr_accrual', '770.00');

    $post(10000, '2026-04-10')->assertCreated()
        ->assertJsonPath('data.enasarco_base', '6000.00')
        ->assertJsonPath('data.enasarco_agent', '525.00')
        ->assertJsonPath('data.firr_accrual', '0.00');
});

it('posts a batch in order, each payment counting in the year to date of the next, and rolls everything back on a failure', function (): void {
    $worker = wrRecipient($this, 'occasional_worker');
    $author = wrRecipient($this, 'author_royalty');
    wrInlSent($this, $worker);

    $items = [
        ['recipient_profile_id' => $worker->id, 'gross_amount' => 4000, 'payment_date' => '2026-06-15', 'service_date' => '2026-06-10'],
        ['recipient_profile_id' => $worker->id, 'gross_amount' => 2000, 'payment_date' => '2026-06-16', 'service_date' => '2026-06-10'],
        ['recipient_profile_id' => $author->id, 'gross_amount' => 1000, 'payment_date' => '2026-06-16'],
    ];

    $this->postJson(WR_API.'/settlements/batch', ['items' => $items])->assertCreated()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.inps_total', '0.00')
        ->assertJsonPath('data.1.inps_base', '1000.00')
        ->assertJsonPath('data.2.irpef_withholding', '120.00');
    expect(RecipientSettlement::query()->count())->toBe(3);

    $failing = [
        ['recipient_profile_id' => $author->id, 'gross_amount' => 500, 'payment_date' => '2026-06-17'],
        ['recipient_profile_id' => $worker->id, 'gross_amount' => 500, 'payment_date' => '2026-09-17', 'service_date' => '2026-09-10'],
    ];
    $this->postJson(WR_API.'/settlements/batch', ['items' => $failing])->assertStatus(422)->assertJsonPath('code', 'inl_notification_expired');
    expect(RecipientSettlement::query()->count())->toBe(3);

    $this->postJson(WR_API.'/settlements/batch', ['items' => []])->assertStatus(422)->assertJsonValidationErrors(['items']);
    $this->postJson(WR_API.'/settlements/batch', ['items' => [['gross_amount' => 1]]])->assertStatus(422)->assertJsonValidationErrors(['items.0.recipient_profile_id', 'items.0.payment_date']);
});

it('lists the tax register with filters and pagination', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $sports = wrRecipient($this, 'amateur_sports');

    foreach ([['2026-05-10', $author], ['2026-06-10', $author], ['2026-06-11', $sports]] as [$date, $recipient]) {
        $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $recipient->id, 'gross_amount' => 100, 'payment_date' => $date])->assertCreated();
    }

    $this->getJson(WR_API.'/settlements')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.payment_date', '2026-06-11');
    $this->getJson(WR_API.'/settlements?recipient_profile_id='.$author->id)->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(WR_API.'/settlements?regime=amateur_sports')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson(WR_API.'/settlements?accrual_month=2026-06')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson(WR_API.'/settlements?tax_year=2025')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(WR_API.'/settlements?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson(WR_API.'/settlements?accrual_month=2026-13')->assertStatus(422)->assertJsonValidationErrors(['accrual_month']);
});

it('keeps settlements of other companies out of the register and the year to date', function (): void {
    $author = wrRecipient($this, 'author_royalty');
    $id = $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 100, 'payment_date' => '2026-06-15'])->assertCreated()->json('data.id');

    treasuryActor($this, 'accountant', $this->otherTenant);
    $this->getJson(WR_API.'/settlements')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson(WR_API."/settlements/{$id}")->assertNotFound();
    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $author->id, 'gross_amount' => 100, 'payment_date' => '2026-06-15'])->assertStatus(422)->assertJsonValidationErrors(['recipient_profile_id']);
});

it('computes Swiss compensations in CHF, with AVS from the first franc in the cultural sector and no F24', function (): void {
    $artist = wrRecipient($this, 'swiss_foreign_artist');

    $this->postJson(WR_API.'/settlements', ['recipient_profile_id' => $artist->id, 'gross_amount' => 4000, 'payment_date' => '2026-06-15', 'performance_days' => 2])->assertCreated()
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.jurisdiction', 'CH')
        ->assertJsonPath('data.acquisition_expenses', '2000.00')
        ->assertJsonPath('data.source_tax', '100.00') // 1,000 a day -> 5% of 2,000
        ->assertJsonPath('data.avs_employee', '212.00')
        ->assertJsonPath('data.avs_employer', '212.00')
        ->assertJsonPath('data.net_payable', '3688.00')
        ->assertJsonPath('data.f24_lines', [])
        ->assertJsonPath('data.f24_due_date', null)
        ->assertJsonPath('data.cu_causal', null);
});
