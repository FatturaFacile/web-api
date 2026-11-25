<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Illuminate\Support\Carbon;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\PecReceipt;

/**
 * The acceptance (ricevuta di accettazione) and delivery (ricevuta di consegna) receipts of a certified e-mail notice.
 * A delivery receipt marks the notice delivered; a failed-delivery or refused-acceptance receipt marks it failed.
 */
final class ReceiptService
{
    public const string Acceptance = 'accettazione';

    public const string Delivery = 'consegna';

    public const string DeliveryFailed = 'mancata_consegna';

    public const string NotAccepted = 'non_accettazione';

    /** @var list<string> */
    public const array Kinds = [self::Acceptance, self::Delivery, self::DeliveryFailed, self::NotAccepted];

    /** Value of the X-Ricevuta header of a PEC receipt, by receipt kind. */
    private const array HeaderKinds = [
        'accettazione' => self::Acceptance,
        'avvenuta-consegna' => self::Delivery,
        'presa-in-carico' => self::Acceptance,
        'errore-consegna' => self::DeliveryFailed,
        'non-accettazione' => self::NotAccepted,
    ];

    public function __construct(private readonly HistoryRecorder $history) {}

    public function record(Notice $notice, string $kind, ?string $messageId, ?Carbon $receivedAt, ?string $notes, ?string $actorId): PecReceipt
    {
        if (! in_array($kind, self::Kinds, true)) {
            throw new DebtCollectionException("Unknown receipt kind [{$kind}].", 'invalid_receipt_kind');
        }

        if ($notice->channel !== 'pec' || $notice->status === 'draft') {
            throw new DebtCollectionException('Receipts exist only for notices sent by PEC.', 'receipt_not_allowed');
        }

        $existing = PecReceipt::query()->where('notice_id', $notice->id)->where('kind', $kind)->first();

        if ($existing !== null) {
            return $existing;
        }

        $receipt = PecReceipt::create([
            'notice_id' => $notice->id,
            'kind' => $kind,
            'message_id' => $messageId,
            'received_at' => $receivedAt ?? now(),
            'notes' => $notes,
        ]);

        if ($kind === self::Delivery) {
            $notice->update(['status' => 'delivered']);
            $this->history->record((string) $notice->customer_id, 'notice_delivered', 'notice', (string) $notice->id, (int) $notice->level, (string) $notice->total, (string) $notice->currency, ['number' => $notice->number], $actorId);
        } elseif ($kind === self::DeliveryFailed || $kind === self::NotAccepted) {
            $notice->update(['status' => 'failed', 'error' => $kind]);
        }

        $this->history->record((string) $notice->customer_id, 'receipt_registered', 'notice', (string) $notice->id, (int) $notice->level, null, null, ['kind' => $kind, 'message_id' => $messageId, 'number' => $notice->number], $actorId);

        return $receipt;
    }

    /**
     * Reads a raw PEC receipt (RFC 822 source of the message from the certified mailbox) and records it on the notice
     * whose Message-ID it references. Returns null when the message is not a receipt of a known notice.
     */
    public function ingest(string $raw): ?PecReceipt
    {
        $headers = $this->headersOf($raw);
        $kind = self::HeaderKinds[strtolower(trim($headers['x-ricevuta'] ?? ''))] ?? null;
        $reference = trim($headers['x-riferimento-message-id'] ?? '', " <>\t\r\n");

        if ($kind === null || $reference === '') {
            return null;
        }

        $notice = Notice::query()->where('pec_message_id', $reference)->first();

        if ($notice === null) {
            return null;
        }

        $date = isset($headers['date']) ? Carbon::parse($headers['date']) : null;

        return $this->record($notice, $kind, trim($headers['message-id'] ?? '', " <>\t\r\n") ?: null, $date, null, null);
    }

    /**
     * @return array<string, string> lower-case header name => value (folded lines joined)
     */
    private function headersOf(string $raw): array
    {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $head = (string) preg_replace("/\r?\n[ \t]+/", ' ', $head);
        $headers = [];

        foreach (preg_split("/\r?\n/", $head) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] ??= trim($value);
            }
        }

        return $headers;
    }
}
