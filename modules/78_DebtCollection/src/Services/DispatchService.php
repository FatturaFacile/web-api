<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Services\MessageDispatcher;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\Notice;
use Modules\SDI\Services\PecMailer;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one notice on its channel and records the outcome on the notice and in the customer history.
 *
 *  - email, sms, whatsapp: through the communication module (drivers and consent of the company), the PDF attached to e-mail.
 *  - pec: through the certified mailbox of the company; the Message-ID is kept to match the acceptance and delivery receipts.
 *  - registered_mail: the print package (PDF) is the dispatch; the notice is marked sent when the user confirms the posting.
 *
 * A delivery failure never throws: the notice becomes `failed` with the error, so a batch carries on with the others.
 */
final class DispatchService
{
    public const array Channels = ['email', 'sms', 'whatsapp', 'pec', 'registered_mail'];

    public function __construct(
        private readonly NoticeRenderer $renderer,
        private readonly MessageDispatcher $dispatcher,
        private readonly PecMailer $pec,
        private readonly HistoryRecorder $history,
    ) {}

    public function send(Notice $notice, ?string $channel, ?string $actorId): Notice
    {
        if (! in_array($notice->status, ['draft', 'queued', 'failed'], true)) {
            throw new DebtCollectionException('The notice was already sent or cancelled.', 'notice_not_sendable');
        }

        $channel ??= (string) $notice->channel;

        if (! in_array($channel, self::Channels, true)) {
            throw new DebtCollectionException("Unknown channel [{$channel}].", 'invalid_channel');
        }

        $notice->loadMissing('lines');
        $notice->fill(['channel' => $channel, 'attempts' => (int) $notice->attempts + 1])->save();

        try {
            $messageId = $this->transmit($notice, $channel, $actorId);
        } catch (Throwable $exception) {
            $notice->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);
            $this->history->record((string) $notice->customer_id, 'notice_failed', 'notice', (string) $notice->id, (int) $notice->level, (string) $notice->total, (string) $notice->currency, ['channel' => $channel, 'error' => mb_substr($exception->getMessage(), 0, 300)], $actorId);

            return $notice->refresh();
        }

        $notice->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'pec_message_id' => $messageId ?? $notice->pec_message_id]);
        $this->history->record((string) $notice->customer_id, $notice->kind === 'messa_in_mora' ? 'messa_in_mora_sent' : 'notice_sent', 'notice', (string) $notice->id, (int) $notice->level, (string) $notice->total, (string) $notice->currency, ['channel' => $channel, 'number' => $notice->number], $actorId);

        return $notice->refresh();
    }

    /**
     * @return string|null the PEC Message-ID when the channel is pec
     */
    private function transmit(Notice $notice, string $channel, ?string $actorId): ?string
    {
        $contact = Contact::query()->findOrFail($notice->customer_id);

        return match ($channel) {
            'email', 'sms', 'whatsapp' => $this->viaCommunication($notice, $contact, $channel, $actorId),
            'pec' => $this->viaPec($notice, $contact),
            default => $this->viaRegisteredMail($notice),
        };
    }

    private function viaCommunication(Notice $notice, Contact $contact, string $channel, ?string $actorId): null
    {
        $recipient = $channel === 'email' ? trim((string) $contact->email) : trim((string) $contact->phone);

        if ($recipient === '') {
            throw new DebtCollectionException('The customer has no address for this channel.', 'no_channel_address');
        }

        $isEmail = $channel === 'email';
        $body = $isEmail ? (string) $notice->body : $this->shortText($notice);
        $pdf = $isEmail ? $this->renderer->pdf($notice) : null;

        $log = $this->dispatcher->dispatch(
            $channel,
            new DeliveryMessage($channel, $recipient, (string) $notice->subject, $body, $isEmail ? $notice->number.'.pdf' : null, $pdf),
            ['message_type' => 'dunning', 'document_type' => 'dunning_notice', 'document_id' => (string) $notice->id, 'sent_by' => $actorId, 'locale' => $notice->locale],
            $this->dispatcher->settings($channel),
        );

        if ($log->status === 'failed') {
            throw new DebtCollectionException((string) ($log->error ?? 'Delivery failed.'), 'delivery_failed');
        }

        return null;
    }

    private function viaPec(Notice $notice, Contact $contact): string
    {
        $tenant = Tenant::query()->findOrFail($notice->tenant_id);
        $recipient = trim((string) $contact->pec);
        $sender = (string) ($tenant->pec_email ?: $tenant->sdi_pec_username);

        if ($recipient === '') {
            throw new DebtCollectionException('The customer has no PEC address.', 'no_channel_address');
        }

        if ($sender === '' || empty($tenant->sdi_pec_smtp_host)) {
            throw new DebtCollectionException('The certified mailbox of the company is not configured.', 'pec_not_configured');
        }

        $messageId = (string) Str::uuid().'@'.(Str::after($sender, '@') ?: 'pec.local');
        $email = (new Email)
            ->from($sender)
            ->to($recipient)
            ->subject((string) $notice->subject)
            ->text((string) $notice->body)
            ->attach($this->renderer->pdf($notice), $notice->number.'.pdf', 'application/pdf');
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        $this->pec->send($tenant, $email);

        return $messageId;
    }

    private function viaRegisteredMail(Notice $notice): null
    {
        // The print package is produced on demand (GET notices/{id}/pdf and /export); posting it is done by the user.
        $this->renderer->pdf($notice);

        return null;
    }

    private function shortText(Notice $notice): string
    {
        return trim((string) $notice->subject.' - '.$notice->number.' - '.$notice->currency.' '.number_format((float) $notice->total, 2, '.', "'").' - '.($notice->new_due_date?->format('d.m.Y') ?? ''));
    }
}
