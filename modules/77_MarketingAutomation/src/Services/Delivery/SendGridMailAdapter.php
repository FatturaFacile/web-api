<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Illuminate\Support\Facades\Http;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;

final class SendGridMailAdapter implements MailProviderAdapter
{
    public function provider(): MailProvider
    {
        return MailProvider::SendGrid;
    }

    public function send(OutboundEmail $email, array $config): array
    {
        $response = Http::withToken((string) ($config['key'] ?? ''))->acceptJson()->post('https://api.sendgrid.com/v3/mail/send', array_filter([
            'personalizations' => [['to' => [['email' => $email->to]]]],
            'from' => ['email' => $email->fromEmail, 'name' => $email->fromName],
            'reply_to' => $email->replyTo !== null ? ['email' => $email->replyTo] : null,
            'subject' => $email->subject,
            'content' => [['type' => 'text/plain', 'value' => $email->text], ['type' => 'text/html', 'value' => $email->html]],
            'headers' => $email->headers === [] ? null : $email->headers,
        ]));

        if (! $response->successful()) {
            throw new MailDeliveryException('SendGrid: '.mb_substr($response->body(), 0, 300), $response->status() === 400);
        }

        return ['reference' => $response->header('X-Message-Id') ?: null];
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function parseWebhook(array $payload): array
    {
        $events = [];
        foreach (array_is_list($payload) ? $payload : [$payload] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $type = match ((string) ($event['event'] ?? '')) {
                'bounce' => (($event['type'] ?? 'bounce') === 'blocked' ? 'soft_bounce' : 'hard_bounce'),
                'deferred' => 'soft_bounce',
                'spamreport' => 'complaint',
                'delivered' => 'delivered',
                default => null,
            };
            if ($type !== null) {
                $reference = isset($event['sg_message_id']) ? explode('.', (string) $event['sg_message_id'])[0] : null;
                $events[] = ['type' => $type, 'address' => isset($event['email']) ? (string) $event['email'] : null, 'reference' => $reference];
            }
        }

        return $events;
    }
}
