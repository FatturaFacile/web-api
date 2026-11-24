<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\Drivers\LocalDriver;
use Modules\Communication\Drivers\MailDriver;
use Modules\Communication\Drivers\SmtpDriver;
use Modules\Communication\Drivers\TelegramDriver;
use Modules\Communication\Drivers\TwilioDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Models\DeliveryLog;

/**
 * Sends an invoice or a quote on a channel and logs the attempt. The text comes from the template `document.invoice` or
 * `document.quote` of the company in the requested language when there is one, otherwise from the standard wording.
 */
final readonly class DocumentSender
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private DocumentPdfService $pdf,
        private MessageDispatcher $dispatcher,
        private TemplateRenderer $templates,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, class-string<DeliveryDriver>>
     */
    public static function driversOf(string $channel): array
    {
        return match ($channel) {
            'email' => ['mail' => MailDriver::class, 'smtp' => SmtpDriver::class, 'local' => LocalDriver::class],
            'telegram' => ['telegram' => TelegramDriver::class, 'local' => LocalDriver::class],
            default => ['twilio' => TwilioDriver::class, 'local' => LocalDriver::class],
        };
    }

    /**
     * Send the message.
     *
     * @throws ValidationException when the channel is off or the document has nobody to send to
     */
    public function send(string $documentType, string $documentId, string $channel, ?string $recipient, ?string $note, ?string $userId, ?string $locale = null): DeliveryLog
    {
        $document = $documentType === 'invoice' ? Invoice::with('contact')->findOrFail($documentId) : Quote::with('contact')->findOrFail($documentId);
        $settings = $this->dispatcher->settings($channel);

        $recipient ??= match ($channel) {
            'email' => $document->contact?->email,
            'sms', 'whatsapp' => $document->contact?->phone,
            default => null,
        };

        if (empty($recipient)) {
            throw ValidationException::withMessages(['recipient' => __('sales.delivery_no_recipient')]);
        }

        $number = (string) ($documentType === 'invoice' ? $document->invoice_number : $document->quote_number);
        $templateCode = "document.{$documentType}";
        $template = $this->templates->resolve($templateCode, $channel, $locale);
        $rendered = $template === null ? null : $this->templates->render($template, [
            'number' => $number,
            'note' => (string) $note,
            'contact_name' => (string) ($document->contact?->company_name ?: trim($document->contact?->first_name.' '.$document->contact?->last_name)),
            'company_name' => (string) $this->tenantContext->getTenant()?->name,
        ]);

        $subject = $rendered['subject'] ?? __('sales.delivery_subject', ['number' => $number]);
        $body = $rendered['body'] ?? trim($subject."\n\n".(string) $note);
        $pdf = $channel === 'email' ? $this->pdf->render($document) : null;

        return $this->dispatcher->dispatch(
            $channel,
            new DeliveryMessage($channel, $recipient, $subject, $body, $pdf?->filename, $pdf?->content),
            ['message_type' => 'document', 'document_type' => $documentType, 'document_id' => $documentId, 'sent_by' => $userId, 'template_code' => $template === null ? null : $templateCode, 'locale' => $template?->locale],
            $settings
        );
    }
}
