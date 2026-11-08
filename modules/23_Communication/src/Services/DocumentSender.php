<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Pdf\DocumentPdfService;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\Drivers\LocalDriver;
use Modules\Communication\Drivers\MailDriver;
use Modules\Communication\Drivers\TelegramDriver;
use Modules\Communication\Drivers\TwilioDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\DeliveryLog;

/**
 * Sends a document on a channel and logs the attempt, successful or not. Without a configuration a channel uses the
 * local driver, except e-mail, which uses the mailer of the installation.
 */
final readonly class DocumentSender
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private DocumentPdfService $pdf) {}

    /**
     * @return array<string, class-string<DeliveryDriver>>
     */
    public static function driversOf(string $channel): array
    {
        return match ($channel) {
            'email' => ['mail' => MailDriver::class, 'local' => LocalDriver::class],
            'telegram' => ['telegram' => TelegramDriver::class, 'local' => LocalDriver::class],
            default => ['twilio' => TwilioDriver::class, 'local' => LocalDriver::class],
        };
    }

    /**
     * Send the message.
     */
    public function send(string $documentType, string $documentId, string $channel, ?string $recipient, ?string $note, ?string $userId): DeliveryLog
    {
        $document = $documentType === 'invoice' ? Invoice::with('contact')->findOrFail($documentId) : Quote::with('contact')->findOrFail($documentId);
        $settings = CommunicationChannel::where('channel', $channel)->first();

        if ($settings !== null && ! $settings->is_enabled) {
            throw ValidationException::withMessages(['channel' => __('sales.delivery_channel_disabled')]);
        }

        $recipient ??= match ($channel) {
            'email' => $document->contact?->email,
            'sms', 'whatsapp' => $document->contact?->phone,
            default => null,
        };

        if (empty($recipient)) {
            throw ValidationException::withMessages(['recipient' => __('sales.delivery_no_recipient')]);
        }

        $drivers = self::driversOf($channel);
        $driverName = $settings->driver ?? ($channel === 'email' ? 'mail' : 'local');
        $driver = app($drivers[$driverName]);
        $number = (string) ($documentType === 'invoice' ? $document->invoice_number : $document->quote_number);
        $subject = __('sales.delivery_subject', ['number' => $number]);
        $rendered = $channel === 'email' ? $this->pdf->render($document) : null;

        $message = new DeliveryMessage($channel, $recipient, $subject, trim($subject."\n\n".(string) $note), $rendered?->filename, $rendered?->content);
        $log = ['channel' => $channel, 'driver' => $driver->name(), 'document_type' => $documentType, 'document_id' => $documentId, 'recipient' => $recipient, 'sent_by' => $userId];

        try {
            $result = $driver->send($message, $settings->credentials ?? []);
        } catch (DeliveryFailedException $e) {
            return DeliveryLog::create([...$log, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        }

        return DeliveryLog::create([...$log, 'status' => $result['status'], 'provider_reference' => $result['reference']]);
    }
}
