<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PecReceipt;
use Modules\DebtCollection\Services\ReceiptService;
use Modules\SDI\Services\PecMailer;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

/**
 * Formal notice by PEC to an Italian customer, sent through a fake certified mailbox. Returns the sent notice.
 *
 * @return array<string, mixed>
 */
function dcSentMessaInMora(object $test): array
{
    $mailer = new class extends PecMailer
    {
        public function send(Tenant $tenant, Email $email): void {}
    };
    $test->app->instance(PecMailer::class, $mailer);
    $test->tenant->update(['pec_email' => 'recupero@pec.example.it', 'sdi_pec_smtp_host' => 'smtp.pec.example.it', 'sdi_pec_username' => 'recupero@pec.example.it']);

    $customer = dcCustomer($test, 'Rossi Srl', 'IT');
    $item = dcItem($test, $customer, 'FT-1', '5000.00', '2024-06-01', 'EUR');

    return $test->postJson('/api/v1/debt-collection/messa-in-mora', [
        'customer_id' => $customer->id, 'receivable_item_ids' => [$item->id], 'send_pec' => true, 'locale' => 'it', 'as_of' => '2024-09-01',
    ])->assertCreated()->json('data');
}

beforeEach(function (): void {
    dcSetUp($this, 'IT');
    treasuryActor($this, 'owner');
});

it('issues the messa in mora with D.Lgs. 231/2002 interest and sends it by PEC', function (): void {
    $notice = dcSentMessaInMora($this);

    expect($notice)->toMatchArray(['kind' => 'messa_in_mora', 'level' => 3, 'channel' => 'pec', 'status' => 'sent', 'principal' => '5000.00', 'interest' => '155.38', 'fee' => '40.00', 'total' => '5195.38'])
        ->and($notice['pec_message_id'])->not->toBeEmpty()
        ->and($notice['subject'])->toContain('Messa in mora')
        ->and($notice['body'])->toContain('D.Lgs. 231/2002')->toContain('decreto ingiuntivo');
});

it('records the acceptance and delivery receipts and marks the notice delivered', function (): void {
    $notice = dcSentMessaInMora($this);

    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/pec-receipts", ['kind' => 'accettazione', 'received_at' => '2024-09-01T10:00:00Z'])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonCount(1, 'data.receipts');

    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/pec-receipts", ['kind' => 'consegna', 'message_id' => 'opec292.20240901.consegna@pec.example.it'])
        ->assertCreated()->assertJsonPath('data.status', 'delivered')->assertJsonCount(2, 'data.receipts');

    expect(PecReceipt::query()->where('notice_id', $notice['id'])->pluck('kind')->sort()->values()->all())->toBe(['accettazione', 'consegna']);
});

it('records a receipt only once per kind', function (): void {
    $notice = dcSentMessaInMora($this);
    $payload = ['kind' => 'accettazione'];

    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/pec-receipts", $payload)->assertCreated();
    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/pec-receipts", $payload)->assertCreated()->assertJsonCount(1, 'data.receipts');
});

it('marks the notice failed when the delivery fails', function (): void {
    $notice = dcSentMessaInMora($this);

    $this->postJson("/api/v1/debt-collection/notices/{$notice['id']}/pec-receipts", ['kind' => 'mancata_consegna'])->assertCreated()->assertJsonPath('data.status', 'failed');
});

it('refuses receipts for a notice that did not go by PEC', function (): void {
    $customer = dcCustomer($this, 'Verdi Srl', 'IT');
    $item = dcItem($this, $customer, 'FT-2', '100.00', '2025-01-01', 'EUR');
    $id = $this->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $customer->id, 'currency' => 'EUR', 'level' => 0, 'receivable_item_ids' => [$item->id]]],
        'as_of' => '2025-01-16', 'channel' => 'email', 'dispatch' => true,
    ])->assertCreated()->json('data.notices.0.id');

    $this->postJson("/api/v1/debt-collection/notices/{$id}/pec-receipts", ['kind' => 'consegna'])->assertUnprocessable()->assertJsonPath('code', 'receipt_not_allowed');
});

it('reads a receipt message of the certified mailbox and matches it to the notice by Message-ID', function (): void {
    $notice = dcSentMessaInMora($this);
    $raw = "From: posta-certificata@pec.example.it\r\nTo: recupero@pec.example.it\r\nSubject: CONSEGNA: Messa in mora\r\nDate: Sun, 01 Sep 2024 10:05:00 +0200\r\n"
        ."Message-ID: <opec.consegna.1@pec.example.it>\r\nX-Ricevuta: avvenuta-consegna\r\nX-Riferimento-Message-ID: <{$notice['pec_message_id']}>\r\n\r\nReceipt body";

    $receipt = treasuryInTenant($this->tenant, fn () => app(ReceiptService::class)->ingest($raw));

    expect($receipt)->not->toBeNull()
        ->and($receipt->kind)->toBe('consegna')
        ->and($receipt->message_id)->toBe('opec.consegna.1@pec.example.it')
        ->and(Notice::query()->findOrFail($notice['id'])->status)->toBe('delivered');
});

it('ignores a message that is not a receipt of a known notice', function (): void {
    dcSentMessaInMora($this);

    $receipt = treasuryInTenant($this->tenant, fn () => app(ReceiptService::class)->ingest("Subject: hello\r\nX-Ricevuta: accettazione\r\nX-Riferimento-Message-ID: <unknown@pec>\r\n\r\nbody"));

    expect($receipt)->toBeNull();
});

it('refuses the messa in mora for a Swiss debtor', function (): void {
    $customer = dcCustomer($this, 'Muster AG', 'CH');
    $item = dcItem($this, $customer, 'INV-CH', '100.00', '2024-01-01');

    $this->postJson('/api/v1/debt-collection/messa-in-mora', ['customer_id' => $customer->id, 'receivable_item_ids' => [$item->id], 'as_of' => '2024-09-01'])
        ->assertUnprocessable()->assertJsonPath('code', 'wrong_jurisdiction');
});

it('keeps the legal steps closed to the members who may only read', function (): void {
    treasuryActor($this, 'sales_manager');
    $customer = dcCustomer($this, 'Bianchi Srl', 'IT');
    $item = dcItem($this, $customer, 'FT-3', '100.00', '2024-01-01', 'EUR');

    $this->postJson('/api/v1/debt-collection/messa-in-mora', ['customer_id' => $customer->id, 'receivable_item_ids' => [$item->id], 'as_of' => '2024-09-01'])->assertForbidden();
});
