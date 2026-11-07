<?php

declare(strict_types=1);

namespace Modules\SDI\Gateways;

use App\Exceptions\SdiProviderException;
use App\Models\Tenant;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\SDI\Contracts\RereadsInboundMessages;
use Modules\SDI\Contracts\SdiGateway;
use Modules\SDI\DTOs\SdiInboundItem;
use Modules\SDI\Services\P7mExtractor;
use Modules\SDI\Services\PecEnvelopeVerifier;
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
final class PecGateway implements RereadsInboundMessages, SdiGateway
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
        private readonly PecEnvelopeVerifier $envelopes,
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
                $items = [...$items, ...$this->read($tenant, $mailbox->fetch($uid), $this->sourceReference($tenant, $uid), $uid)];
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

    public function reread(Tenant $tenant, string $raw, string $sourceReference): array
    {
        return $this->read($tenant, $raw, $sourceReference, null);
    }

    /**
     * Turn one message into items. A message that claims to concern SDI but is not a certified message of SDI, or whose
     * files cannot be read or whose signature does not verify, becomes a quarantine item holding the whole message, so
     * the cursor never moves past something that was not stored.
     *
     * @param  int|null  $uid  null when a stored message is read again: nothing is skipped and no cursor is carried
     * @return list<SdiInboundItem>
     */
    private function read(Tenant $tenant, string $raw, string $sourceReference, ?int $uid): array
    {
        $message = $this->messages->parse($raw);
        $nothing = $uid === null ? [] : [SdiInboundItem::skip($uid)];

        // Acceptance and delivery receipts of the PEC providers quote our own message, attachment included.
        if (isset($message['headers']['x-ricevuta'])) {
            return $nothing;
        }

        $sdiFiles = array_values(array_filter(
            $message['attachments'],
            fn (array $attachment): bool => $this->receipts->isReceipt($attachment['name']) || preg_match(self::InvoiceFilePattern, $attachment['name']) === 1,
        ));

        if ($sdiFiles === [] && ! str_contains(strtolower($raw), 'pec.fatturapa.it')) {
            return $nothing;
        }

        $distrust = $this->envelopes->reasonToDistrust($message);

        if ($distrust !== null) {
            return [SdiInboundItem::quarantine($raw, $sourceReference, [$distrust], $this->details($message), $uid)];
        }

        $items = [];
        $failures = [];
        $signatureVerified = null;
        $fromSdiAboutOurFile = $message['attachments'] === [];

        foreach ($sdiFiles as $attachment) {
            if ($this->receipts->isReceipt($attachment['name'])) {
                $fromSdiAboutOurFile = true;

                try {
                    $receipt = $this->receipts->parse($attachment['name'], $this->p7m->extractReceipt($attachment['content']));
                } catch (InvalidArgumentException) {
                    $failures[] = "receipt_unreadable: {$attachment['name']}";

                    continue;
                }

                if ($receipt !== null) {
                    $items[] = SdiInboundItem::notification($receipt['uuid'], $receipt['event_type'], $receipt['message'], $uid);
                }

                continue;
            }

            try {
                $opened = $this->p7m->open($attachment['content']);
            } catch (Throwable $e) {
                report($e);
                $failures[] = "invoice_unreadable: {$attachment['name']}";

                continue;
            }

            if ($opened['signature_verified'] === false) {
                $signatureVerified = false;
                $failures[] = "p7m_signature_invalid: {$attachment['name']}";

                continue;
            }

            $signatureVerified ??= $opened['signature_verified'];
            $items[] = SdiInboundItem::invoice($attachment['name'], $opened['xml'], $attachment['name'], $uid, $opened['signature_verified']);
        }

        if ($fromSdiAboutOurFile) {
            $this->rememberAssignedAddress($tenant, $raw."\n".$message['text']);
        }

        // The quarantine item comes first: it is stored before anything else of the message is applied.
        if ($failures !== []) {
            array_unshift($items, SdiInboundItem::quarantine($raw, $sourceReference, $failures, $this->details($message), $uid, $signatureVerified));
        }

        return $items === [] ? $nothing : $items;
    }

    /**
     * Where the message was read, as an IMAP URL (RFC 5092).
     */
    private function sourceReference(Tenant $tenant, int $uid): string
    {
        return sprintf('imap://%s/INBOX;UID=%d', $tenant->sdi_pec_imap_host, $uid);
    }

    /**
     * @param  array{headers: array<string, string>, attachments: list<array{name: string, content: string}>}  $message
     * @return array<string, mixed>
     */
    private function details(array $message): array
    {
        return [
            'message_id' => $message['headers']['message-id'] ?? null,
            'from' => isset($message['headers']['from']) ? mb_substr($message['headers']['from'], 0, 255) : null,
            'subject' => isset($message['headers']['subject']) ? mb_substr($message['headers']['subject'], 0, 255) : null,
            'transport' => $message['headers']['x-trasporto'] ?? null,
            'attachments' => array_map(fn (array $attachment): string => $attachment['name'], $message['attachments']),
        ];
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
