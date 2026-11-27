<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Priority alert of the foreign VAT refund module: a peremptory deadline within 60, 30 or 15 days (or just missed), or a fiscal
 * representative mandate / certificate of taxable status that is missing or about to expire. In-app (the client builds the text from
 * the key foreignVat.notification.<type>) and email.
 */
final class ForeignVatAlertNotification extends Notification
{
    public const string DEADLINE = 'deadline';

    public const string DEADLINE_EXPIRED = 'deadline_expired';

    public const string MANDATE_MISSING = 'mandate_missing';

    public const string CERTIFICATE_MISSING = 'certificate_missing';

    public const string CERTIFICATE_EXPIRING = 'certificate_expiring';

    public const string CERTIFICATE_EXPIRED = 'certificate_expired';

    /**
     * @param  array<string, string|int>  $params  country, year, deadline, days, amount, currency, expiry (what the type needs)
     */
    public function __construct(public readonly string $type, public readonly array $params) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = (string) ($notifiable->locale ?? app()->getLocale());

        return (new MailMessage)
            ->subject(__('foreign_vat_refund.notification.'.$this->type.'_subject', $this->params, $locale))
            ->line(__('foreign_vat_refund.notification.'.$this->type.'_line', $this->params, $locale));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'foreign_vat_refund.'.$this->type, 'key' => 'foreignVat.notification.'.$this->type, 'params' => $this->params];
    }
}
