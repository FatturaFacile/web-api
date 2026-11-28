<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\DebtCollection\Models\Notice;
use Modules\SDI\Services\PecMailer;
use Symfony\Component\Mime\Email;

require_once __DIR__.'/DebtCollectionSupport.php';

uses(RefreshDatabase::class);

/**
 * A PecMailer that keeps the messages instead of sending them.
 */
function dcFakePec(object $test): object
{
    $mailer = new class extends PecMailer
    {
        /** @var list<Email> */
        public array $sent = [];

        public function send(Tenant $tenant, Email $email): void
        {
            $this->sent[] = $email;
        }
    };

    app()->instance(PecMailer::class, $mailer);
    $test->tenant->update(['pec_email' => 'recupero@pec.example.ch', 'sdi_pec_smtp_host' => 'smtp.pec.example.ch', 'sdi_pec_username' => 'recupero@pec.example.ch']);

    return $mailer;
}

/**
 * One draft level 0 notice per customer, generated on 16 January 2025.
 *
 * @param  list<Contact>  $customers
 * @return list<string>
 */
function dcDraftNotices(object $test, array $customers, string $channel = 'registered_mail'): array
{
    $ids = [];

    foreach ($customers as $index => $customer) {
        $item = dcItem($test, $customer, 'INV-'.$index, '400.00', '2025-01-01', $customer->country === 'IT' ? 'EUR' : 'CHF');
        $currency = $customer->country === 'IT' ? 'EUR' : 'CHF';
        $ids[] = $test->postJson('/api/v1/debt-collection/proposals/generate', [
            'groups' => [['customer_id' => $customer->id, 'currency' => $currency, 'level' => 0, 'receivable_item_ids' => [$item->id]]],
            'as_of' => '2025-01-16', 'channel' => $channel,
        ])->assertCreated()->json('data.notices.0.id');
    }

    return $ids;
}

beforeEach(function (): void {
    dcSetUp($this);
});

it('marks a registered-mail notice as sent once the print package is ready', function (): void {
    [$id] = dcDraftNotices($this, [dcCustomer($this, 'Muster AG')]);

    $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.attempts', 1);

    $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertUnprocessable()->assertJsonPath('code', 'notice_not_sendable');
});

it('records a failure on the notice when the customer has no address for the channel', function (): void {
    [$id] = dcDraftNotices($this, [dcCustomer($this, 'Senza Telefono AG')], 'sms');

    $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.attempts', 1);

    expect(Notice::query()->findOrFail($id)->error)->not->toBeEmpty();
});

it('sends by PEC through the certified mailbox and keeps the Message-ID for the receipts', function (): void {
    $mailer = dcFakePec($this);
    [$id] = dcDraftNotices($this, [dcCustomer($this, 'Rossi Srl', 'IT')], 'pec');

    $response = $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
    $email = $mailer->sent[0];

    expect($mailer->sent)->toHaveCount(1)
        ->and($email->getTo()[0]->getAddress())->toBe('rossi-srl@pec.example.it')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getHeaders()->get('Message-ID')->getId())->toBe($response->json('data.pec_message_id'));
});

it('fails a PEC notice when the company has no certified mailbox configured', function (): void {
    [$id] = dcDraftNotices($this, [dcCustomer($this, 'Rossi Srl', 'IT')], 'pec');

    $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertOk()->assertJsonPath('data.status', 'failed');
});

it('dispatches a batch, counts what went out and what failed, and retries the failures', function (): void {
    $ids = dcDraftNotices($this, [dcCustomer($this, 'Con Posta AG'), dcCustomer($this, 'Senza PEC AG')]);

    $batch = $this->postJson('/api/v1/debt-collection/batches', ['notice_ids' => $ids, 'channel' => 'pec'])->assertCreated()->json('data');

    // Neither Swiss customer has a PEC address: every notice was tried and the whole batch failed.
    $this->getJson("/api/v1/debt-collection/batches/{$batch['id']}")->assertOk()
        ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.total', 2)->assertJsonPath('data.sent', 0)->assertJsonPath('data.failed', 2);

    $this->postJson("/api/v1/debt-collection/batches/{$batch['id']}/retry")->assertOk();

    $this->getJson("/api/v1/debt-collection/batches/{$batch['id']}")->assertOk()->assertJsonPath('data.failed', 2);
    expect(Notice::query()->whereIn('id', $ids)->min('attempts'))->toBe(2);
});

it('refuses to batch a notice that was already sent', function (): void {
    [$id] = dcDraftNotices($this, [dcCustomer($this, 'Muster AG')]);
    $this->postJson("/api/v1/debt-collection/notices/{$id}/send")->assertOk();

    $this->postJson('/api/v1/debt-collection/batches', ['notice_ids' => [$id], 'channel' => 'registered_mail'])->assertUnprocessable()->assertJsonPath('code', 'notice_not_sendable');
});

it('a batch on registered mail completes with every notice sent', function (): void {
    $ids = dcDraftNotices($this, [dcCustomer($this, 'Uno AG'), dcCustomer($this, 'Due AG')]);

    $batch = $this->postJson('/api/v1/debt-collection/batches', ['notice_ids' => $ids, 'channel' => 'registered_mail'])->assertCreated();

    $this->getJson('/api/v1/debt-collection/batches/'.$batch->json('data.id'))->assertOk()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sent', 2)->assertJsonPath('data.failed', 0);
    $this->getJson('/api/v1/debt-collection/notices?batch_id='.$batch->json('data.id'))->assertOk()->assertJsonPath('meta.total', 2);
});

it('generates and dispatches in one step', function (): void {
    $customer = dcCustomer($this, 'Muster AG');
    $item = dcItem($this, $customer, 'INV-Z', '400.00', '2025-01-01');

    $response = $this->postJson('/api/v1/debt-collection/proposals/generate', [
        'groups' => [['customer_id' => $customer->id, 'currency' => 'CHF', 'level' => 0, 'receivable_item_ids' => [$item->id]]],
        'as_of' => '2025-01-16', 'channel' => 'registered_mail', 'dispatch' => true,
    ])->assertCreated();

    expect($response->json('data.batch.total'))->toBe(1)
        ->and(Notice::query()->first()->status)->toBe('sent');
});
