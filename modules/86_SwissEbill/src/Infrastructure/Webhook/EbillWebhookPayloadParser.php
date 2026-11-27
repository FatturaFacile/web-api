<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Webhook;

use InvalidArgumentException;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Infrastructure\Gateways\GatewayPayloadMapper;

/**
 * Reads the body of a gateway webhook: one event, or {"events": [...]}: delivery receipts, debtor approvals (with the scheduled
 * payment date) and debtor rejections (bank rejection code and the note the debtor typed).
 */
final class EbillWebhookPayloadParser
{
    /**
     * @param  array<string, mixed>  $body
     * @return list<array{notification: GatewayNotification, raw: array<string, mixed>}>
     *
     * @throws EbillException payload_unreadable (422)
     */
    public function parse(array $body): array
    {
        $events = isset($body['events']) && is_array($body['events']) ? $body['events'] : [$body];
        $parsed = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                throw new EbillException('payload_unreadable', 'The webhook body is not an event.', 422);
            }

            try {
                $parsed[] = ['notification' => GatewayPayloadMapper::notification($event), 'raw' => $event];
            } catch (InvalidArgumentException $e) {
                throw new EbillException('payload_unreadable', $e->getMessage(), 422);
            }
        }

        if ($parsed === []) {
            throw new EbillException('payload_unreadable', 'The webhook body carries no event.', 422);
        }

        return $parsed;
    }
}
