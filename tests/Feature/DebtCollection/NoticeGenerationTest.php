<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\DebtCollection\Models\DunningCase;
use Modules\DebtCollection\Models\Event;
use Modules\DebtCollection\Models\Notice;
use Modules\Invoicing\Models\ReceivableItem;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    dcSetUp($this);
    $this->customer = dcCustomer($this, 'Muster AG');
    $this->item = dcItem($this, $this->customer, 'INV-10', '1000.00', '2025-01-01');
    dcCase($this, $this->item, 1, '2025-01-20', '2025-01-30');
});

/**
 * Generates the level 2 notice of the item on 15 February 2025, optionally posting the charges to the ledger.
 */
function dcGenerateLevel2(object $test, bool $postCharges = false, string $channel = 'registered_mail', bool $dispatch = false): TestResponse
{
    return $test->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $test->customer->id, 'currency' => 'CHF', 'level' => 2, 'receivable_item_ids' => [$test->item->id]]],
        'as_of' => '2025-02-15',
        'channel' => $channel,
        'locale' => 'de',
        'post_charges' => $postCharges,
        'dispatch' => $dispatch,
    ]);
}

it('creates a numbered notice with the exact amounts, the rendered text and the progression of the case', function (): void {
    $response = dcGenerateLevel2($this)->assertCreated();

    $response->assertJsonPath('data.notices.0.number', 'DC-2025-000001')
        ->assertJsonPath('data.notices.0.status', 'draft')
        ->assertJsonPath('data.notices.0.level', 2)
        ->assertJsonPath('data.notices.0.principal', '1000.00')
        ->assertJsonPath('data.notices.0.interest', '6.16')
        ->assertJsonPath('data.notices.0.fee', '20.00')
        ->assertJsonPath('data.notices.0.total', '1026.16')
        ->assertJsonPath('data.notices.0.new_due_date', '2025-02-25')
        ->assertJsonPath('data.notices.0.subject', '2. Mahnung')
        ->assertJsonPath('data.notices.0.lines.0.interest_days', 45);

    expect($response->json('data.notices.0.body'))->toContain('Muster AG')->toContain('1026.16')->toContain('25.02.2025')->toContain('Art. 104 CO');

    $case = DunningCase::query()->where('receivable_item_id', $this->item->id)->firstOrFail();

    expect($case->current_level)->toBe(2)
        ->and($case->last_notice_on->toDateString())->toBe('2025-02-15')
        ->and($case->deadline_on->toDateString())->toBe('2025-02-25')
        ->and(Event::query()->where('customer_id', $this->customer->id)->where('event', 'notice_generated')->count())->toBe(1);
});

it('refuses to dun again an item that is not due for the requested level', function (): void {
    dcGenerateLevel2($this)->assertCreated();

    dcGenerateLevel2($this)->assertUnprocessable()->assertJsonPath('code', 'item_not_proposable');
});

it('posts interest and fees as a ledger charge item that is never dunned itself', function (): void {
    $charge = dcGenerateLevel2($this, true)->assertCreated()->json('data.notices.0.charge_receivable_item_id');

    $item = ReceivableItem::query()->findOrFail($charge);

    expect($item->document_type)->toBe('dunning_charge')
        ->and((string) $item->open_amount)->toBe('26.16')
        ->and($item->due_date->toDateString())->toBe('2025-02-25')
        ->and(dcProposal($this, '2025-04-30'))->not->toHaveKey('DC-2025-000001');
});

it('does not charge twice the interest already posted by an earlier notice', function (): void {
    dcGenerateLevel2($this, true)->assertCreated();

    // Level 3 on 5 March 2025: 63 days of interest = 8.63, of which 6.16 is already on the ledger.
    $row = dcProposal($this, '2025-03-05')['INV-10'];

    expect($row)->toMatchArray(['status' => 'proposed', 'proposed_level' => 3, 'interest_days' => 63, 'interest_amount' => '2.47', 'fee_amount' => '30.00']);
});

it('cancels an unsent notice, takes the item back to its previous level and cancels the charge', function (): void {
    $notice = dcGenerateLevel2($this, true)->assertCreated()->json('data.notices.0');

    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $case = DunningCase::query()->where('receivable_item_id', $this->item->id)->firstOrFail();

    expect($case->current_level)->toBeNull()
        ->and((string) ReceivableItem::query()->findOrFail($notice['charge_receivable_item_id'])->open_amount)->toBe('0.00')
        ->and(dcProposal($this, '2025-02-15')['INV-10']['proposed_level'])->toBe(0);
});

it('renders the notice as a PDF and an export package', function (): void {
    $id = dcGenerateLevel2($this)->assertCreated()->json('data.notices.0.id');

    $pdf = $this->get("/api/v1/debt-collection/notices/{$id}/pdf")->assertOk();
    $zip = $this->get("/api/v1/debt-collection/notices/{$id}/export")->assertOk();

    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($zip->headers->get('Content-Type'))->toBe('application/zip');
});

it('numbers the notices of a year in sequence', function (): void {
    $second = dcItem($this, $this->customer, 'INV-11', '200.00', '2025-01-01');
    dcCase($this, $second, 1, '2025-01-20', '2025-01-30');

    dcGenerateLevel2($this)->assertCreated();
    $this->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $this->customer->id, 'currency' => 'CHF', 'level' => 2, 'receivable_item_ids' => [$second->id]]],
        'as_of' => '2025-02-15', 'channel' => 'email',
    ])->assertCreated()->assertJsonPath('data.notices.0.number', 'DC-2025-000002');

    expect(Notice::query()->count())->toBe(2);
});

it('does not let a member without write access generate notices', function (): void {
    treasuryActor($this, 'accountant_readonly');

    dcGenerateLevel2($this)->assertForbidden();
});
