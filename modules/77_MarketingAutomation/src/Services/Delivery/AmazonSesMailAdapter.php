<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services\Delivery;

use Illuminate\Support\Facades\Http;
use Modules\MarketingAutomation\Domain\Enums\MailProvider;
use Modules\MarketingAutomation\Exceptions\MailDeliveryException;

/**
 * Amazon SES v2 over HTTPS with Signature V4 (no SDK needed). Bounces and complaints arrive through SNS.
 */
final class AmazonSesMailAdapter implements MailProviderAdapter
{
    public function provider(): MailProvider
    {
        return MailProvider::AmazonSes;
    }

    public function send(OutboundEmail $email, array $config): array
    {
        $region = (string) ($config['region'] ?? 'eu-central-1');
        $host = 'email.'.$region.'.amazonaws.com';
        $headers = [];
        foreach ($email->headers as $name => $value) {
            $headers[] = ['Name' => $name, 'Value' => $value];
        }

        $body = json_encode([
            'FromEmailAddress' => $email->fromName.' <'.$email->fromEmail.'>',
            'Destination' => ['ToAddresses' => [$email->to]],
            'Content' => ['Simple' => [
                'Subject' => ['Data' => $email->subject, 'Charset' => 'UTF-8'],
                'Body' => ['Html' => ['Data' => $email->html, 'Charset' => 'UTF-8'], 'Text' => ['Data' => $email->text, 'Charset' => 'UTF-8']],
                'Headers' => $headers,
            ]],
        ], JSON_THROW_ON_ERROR);

        $signed = $this->sign((string) ($config['key'] ?? ''), (string) ($config['secret'] ?? ''), $region, $host, '/v2/email/outbound-emails', $body);
        $response = Http::withHeaders($signed)->withBody($body, 'application/json')->post('https://'.$host.'/v2/email/outbound-emails');

        if (! $response->successful()) {
            throw new MailDeliveryException('SES: '.mb_substr($response->body(), 0, 300), in_array((string) $response->json('__type'), ['MessageRejected', 'InvalidParameterValue'], true));
        }

        return ['reference' => $response->json('MessageId')];
    }

    public function parseWebhook(array $payload): array
    {
        // SNS notification: the SES event is JSON in "Message".
        $message = is_string($payload['Message'] ?? null) ? (array) json_decode($payload['Message'], true) : $payload;
        $reference = isset($message['mail']['messageId']) ? (string) $message['mail']['messageId'] : null;

        return match ((string) ($message['notificationType'] ?? $message['eventType'] ?? '')) {
            'Bounce' => array_map(static fn (array $r): array => [
                'type' => ($message['bounce']['bounceType'] ?? 'Permanent') === 'Permanent' ? 'hard_bounce' : 'soft_bounce',
                'address' => isset($r['emailAddress']) ? (string) $r['emailAddress'] : null,
                'reference' => $reference,
            ], (array) ($message['bounce']['bouncedRecipients'] ?? [])),
            'Complaint' => array_map(static fn (array $r): array => [
                'type' => 'complaint', 'address' => isset($r['emailAddress']) ? (string) $r['emailAddress'] : null, 'reference' => $reference,
            ], (array) ($message['complaint']['complainedRecipients'] ?? [])),
            'Delivery' => [['type' => 'delivered', 'address' => isset($message['delivery']['recipients'][0]) ? (string) $message['delivery']['recipients'][0] : null, 'reference' => $reference]],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function sign(string $key, string $secret, string $region, string $host, string $path, string $body): array
    {
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $scope = $date.'/'.$region.'/ses/aws4_request';
        $payloadHash = hash('sha256', $body);
        $canonical = "POST\n{$path}\n\ncontent-type:application/json\nhost:{$host}\nx-amz-date:{$now}\n\ncontent-type;host;x-amz-date\n{$payloadHash}";
        $toSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n".hash('sha256', $canonical);

        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'ses', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);

        return [
            'Host' => $host,
            'X-Amz-Date' => $now,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders=content-type;host;x-amz-date, Signature=".hash_hmac('sha256', $toSign, $k),
        ];
    }
}
