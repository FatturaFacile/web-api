<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;
use Throwable;

/**
 * SMTP through a configured Laravel mailer. Infomaniak (SMTP relay) is the same adapter with its own mailer.
 */
class SmtpMailAdapter implements MailProviderAdapter
{
    public function provider(): MailProvider
    {
        return MailProvider::Smtp;
    }

    public function send(OutboundEmail $email, array $config): array
    {
        $mailer = (string) ($config['mailer'] ?? 'smtp');

        try {
            Mail::mailer($mailer)->html($email->html, function (Message $message) use ($email): void {
                $message->to($email->to)->from($email->fromEmail, $email->fromName)->subject($email->subject);
                if ($email->replyTo !== null) {
                    $message->replyTo($email->replyTo);
                }
                $message->text($email->text);
                foreach ($email->headers as $name => $value) {
                    $message->getSymfonyMessage()->getHeaders()->addTextHeader($name, $value);
                }
            });
        } catch (Throwable $e) {
            throw new MailDeliveryException($e->getMessage(), $this->isPermanent($e->getMessage()));
        }

        return ['reference' => null];
    }

    public function parseWebhook(array $payload): array
    {
        // SMTP relays have no common webhook; a mailbox processor posts already normalised events.
        $type = (string) ($payload['type'] ?? '');

        return in_array($type, ['hard_bounce', 'soft_bounce', 'complaint', 'delivered'], true)
            ? [['type' => $type, 'address' => isset($payload['address']) ? (string) $payload['address'] : null, 'reference' => isset($payload['reference']) ? (string) $payload['reference'] : null]]
            : [];
    }

    private function isPermanent(string $error): bool
    {
        return (bool) preg_match('/\b5\.[1-7]\.\d|\b55\d\b|user unknown|mailbox unavailable|does not exist/i', $error);
    }
}
