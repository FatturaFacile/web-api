<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Illuminate\Support\Facades\Http;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;

final class MailgunMailAdapter implements MailProviderAdapter
{
    public function provider(): MailProvider
    {
        return MailProvider::Mailgun;
    }

    public function send(OutboundEmail $email, array $config): array
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.eu.mailgun.net'), '/');
        $form = [
            'from' => $email->fromName.' <'.$email->fromEmail.'>',
            'to' => $email->to,
            'subject' => $email->subject,
            'html' => $email->html,
            'text' => $email->text,
        ];
        foreach ($email->headers as $name => $value) {
            $form['h:'.$name] = $value;
        }

        $response = Http::withBasicAuth('api', (string) ($config['key'] ?? ''))->asForm()->post($endpoint.'/v3/'.($config['domain'] ?? '').'/messages', $form);

        if (! $response->successful()) {
            throw new MailDeliveryException('Mailgun: '.mb_substr($response->body(), 0, 300), $response->status() === 400);
        }

        return ['reference' => trim((string) $response->json('id'), '<>') ?: null];
    }

    public function parseWebhook(array $payload): array
    {
        $data = (array) ($payload['event-data'] ?? []);
        $type = match ((string) ($data['event'] ?? '')) {
            'failed' => (($data['severity'] ?? 'permanent') === 'permanent' ? 'hard_bounce' : 'soft_bounce'),
            'complained' => 'complaint',
            'delivered' => 'delivered',
            default => null,
        };

        return $type === null ? [] : [[
            'type' => $type,
            'address' => isset($data['recipient']) ? (string) $data['recipient'] : null,
            'reference' => isset($data['message']['headers']['message-id']) ? trim((string) $data['message']['headers']['message-id'], '<>') : null,
        ]];
    }
}
