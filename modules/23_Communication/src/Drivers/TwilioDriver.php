<?php

declare(strict_types=1);

namespace Modules\Communication\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Communication\Contracts\DeliveryDriver;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Exceptions\DeliveryFailedException;

/**
 * Twilio Messages API, for SMS and WhatsApp (https://www.twilio.com/docs/messaging/api/message-resource). WhatsApp
 * addresses carry the `whatsapp:` prefix. Accepted means queued by Twilio, not delivered to the phone.
 */
final class TwilioDriver implements DeliveryDriver
{
    public function name(): string
    {
        return 'twilio';
    }

    public function send(DeliveryMessage $message, array $credentials): array
    {
        $accountSid = $credentials['account_sid'] ?? null;
        $authToken = $credentials['auth_token'] ?? null;
        $from = $credentials['from'] ?? null;

        if ($accountSid === null || $authToken === null || $from === null) {
            throw new DeliveryFailedException('The Twilio credentials are not configured.');
        }

        $prefix = $message->channel === 'whatsapp' ? 'whatsapp:' : '';

        try {
            $response = Http::timeout(15)->asForm()->withBasicAuth($accountSid, $authToken)
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($accountSid).'/Messages.json', [
                    'To' => $prefix.$message->recipient,
                    'From' => $prefix.$from,
                    'Body' => $message->body,
                ]);
        } catch (ConnectionException) {
            throw new DeliveryFailedException('Twilio cannot be reached.');
        }

        if (! $response->successful()) {
            throw new DeliveryFailedException((string) ($response->json('message') ?? 'Twilio refused the message.'));
        }

        return ['status' => 'sent', 'reference' => (string) $response->json('sid')];
    }
}
