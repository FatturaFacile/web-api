<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Exceptions\SdiProviderException;
use App\Models\Tenant;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;
use Modules\SDI\Services\P7mExtractor;
use Modules\SDI\Services\PecMailboxFactory;
use Modules\SDI\Services\PecMailer;
use Modules\SDI\Services\PecMessageParser;
use Modules\SDI\Services\SdiReceiptParser;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Free channel through the certified mailbox of the tenant (Allegato A, 1.3): files go to the address SDI assigned to
 * the mailbox, and receipts and supplier invoices are read back from the INBOX over IMAP.
 */
final class PecGateway implements SdiGateway
{
    /**
     * Address for the very first transmission of a mailbox.
     */
    public const string FirstContactAddress = 'sdi01@pec.fatturapa.it';

    /**
     * Size limit of a message accepted by SDI, in bytes.
     */
    private const int MaximumMessageSize = 30 * 1024 * 1024;

    /**
     * Messages read in one run, so a mailbox with a long history cannot hold a worker.
     */
    private const int BatchSize = 100;

    private const string InvoiceFilePattern = '/^[A-Za-z]{2}[A-Za-z0-9]{2,28}_[A-Za-z0-9]{1,5}\.xml(\.p7m)?$/';

    public function __construct(
        private readonly PecMailer $mailer,
        private readonly PecMailboxFactory $mailboxes,
        private readonly PecMessageParser $messages,
        private readonly SdiReceiptParser $receipts,
        private readonly P7mExtractor $p7m,
    ) {}

    public function name(): string
    {
        return 'pec';
    }

    public function send(Tenant $tenant, string $fileName, string $xml): array
    {
        if (strlen($xml) >= self::MaximumMessageSize) {
            throw new SdiProviderException(__('sdi.pec_too_large'));
        }

        $sender = (string) ($tenant->pec_email ?: $tenant->sdi_pec_username);
        $recipient = $tenant->sdi_pec_address ?: self::FirstContactAddress;

        if ($sender === '' || empty($tenant->sdi_pec_smtp_host)) {
            throw new SdiProviderException(__('sdi.pec_not_configured'));
        }

        // Only the file: any body text or extra attachment changes the MIME structure SDI expects.
        $email = (new Email)
            ->from($sender)
            ->to($recipient)
            ->subject($fileName)
            ->attach($xml, $fileName, 'application/xml');

        try {
            $this->mailer->send($tenant, $email);
        } catch (TransportExceptionInterface $e) {
            throw new SdiProviderException(__('sdi.pec_send_failed', ['error' => $e->getMessage()]), previous: $e);
        }

        if ($tenant->sdi_pec_first_sent_at === null) {
            $tenant->update(['sdi_pec_first_sent_at' => now()]);
        }

        return [
            'uuid' => $this->transmissionId($fileName),
            'response' => ['channel' => 'pec', 'recipient' => $recipient, 'file_name' => $fileName],
        ];
    }

    public function poll(Tenant $tenant): array
    {
        if (empty($tenant->sdi_pec_imap_host)) {
            throw new SdiProviderException(__('sdi.pec_not_configured'));
        }

        $mailbox = $this->mailboxes->open($tenant);
        $items = [];

        try {
            foreach (array_slice($mailbox->uidsAfter((int) $tenant->sdi_pec_last_uid), 0, self::BatchSize) as $uid) {
                $items = [...$items, ...$this->read($tenant, $uid, $mailbox->fetch($uid))];
            }
        } finally {
            $mailbox->close();
        }

        return $items;
    }

    public function acknowledge(Tenant $tenant, SdiInboundItem $item): void
    {
        if ($item->cursor !== null && $item->cursor > (int) $tenant->sdi_pec_last_uid) {
            $tenant->update(['sdi_pec_last_uid' => $item->cursor]);
        }
    }

    public function verifyWebhook(Tenant $tenant, Request $request): bool
    {
        return false;
    }

    /**
     * @return list<SdiInboundItem>
     */
    private function read(Tenant $tenant, int $uid, string $raw): array
    {
        $message = $this->messages->parse($raw);

        // Acceptance and delivery receipts of the PEC providers quote our own message, attachment included.
        if (isset($message['headers']['x-ricevuta']) || ! str_contains(strtolower($raw), 'pec.fatturapa.it')) {
            return [SdiInboundItem::skip($uid)];
        }

        $items = [];
        $fromSdiAboutOurFile = $message['attachments'] === [];

        foreach ($message['attachments'] as $attachment) {
            if ($this->receipts->isReceipt($attachment['name'])) {
                $fromSdiAboutOurFile = true;
                $items = [...$items, ...$this->receiptItems($uid, $attachment)];
            } elseif (preg_match(self::InvoiceFilePattern, $attachment['name']) === 1) {
                $items = [...$items, ...$this->invoiceItems($uid, $attachment)];
            }
        }

        if ($fromSdiAboutOurFile) {
            $this->rememberAssignedAddress($tenant, $raw."\n".$message['text']);
        }

        return $items === [] ? [SdiInboundItem::skip($uid)] : $items;
    }

    /**
     * @param  array{name: string, content: string}  $attachment
     * @return list<SdiInboundItem>
     */
    private function receiptItems(int $uid, array $attachment): array
    {
        try {
            $receipt = $this->receipts->parse($attachment['name'], $this->p7m->extractReceipt($attachment['content']));
        } catch (InvalidArgumentException) {
            return [];
        }

        return $receipt === null ? [] : [SdiInboundItem::notification($receipt['uuid'], $receipt['event_type'], $receipt['message'], $uid)];
    }

    /**
     * @param  array{name: string, content: string}  $attachment
     * @return list<SdiInboundItem>
     */
    private function invoiceItems(int $uid, array $attachment): array
    {
        try {
            return [SdiInboundItem::invoice($attachment['name'], $this->p7m->extract($attachment['content']), $attachment['name'], $uid)];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * SDI tells which address the mailbox must use from now on in its first answer (Allegato A, 1.3). Supplier invoices
     * may come from other SDI mailboxes, so only messages about our own files count.
     */
    private function rememberAssignedAddress(Tenant $tenant, string $text): void
    {
        if ($tenant->sdi_pec_first_sent_at === null || ! empty($tenant->sdi_pec_address)) {
            return;
        }

        preg_match_all('/sdi\d+@pec\.fatturapa\.it/i', $text, $addresses);

        foreach ($addresses[0] as $address) {
            if (strcasecmp($address, self::FirstContactAddress) !== 0) {
                $tenant->update(['sdi_pec_address' => strtolower($address)]);

                return;
            }
        }
    }

    private function transmissionId(string $fileName): string
    {
        return (string) preg_replace('/\.xml(\.p7m)?$/i', '', $fileName);
    }
}
