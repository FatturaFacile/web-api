<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\SwissEbill\Application\Dto\GatewayNotification;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillTransmissionRepositoryInterface;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillWebhookEvent;

/**
 * The single entry of gateway notifications, from the webhook and from the polling fallback. The unique (tenant,
 * event id) row of the ledger is written in the same transaction as the state change, so a replayed notification is a no-op and a
 * notification that fails leaves no trace and can be delivered again.
 */
final class EbillNotificationProcessor
{
    public const string OUTCOME_DUPLICATE = 'duplicate';

    public function __construct(
        private readonly EbillTransmissionRepositoryInterface $transmissions,
        private readonly EbillTransmissionLifecycle $lifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{event_id: string, outcome: string}
     *
     * @throws EbillException unknown_transaction (404) when the transmission is not known yet, so the gateway retries later
     */
    public function process(EbillSetting $setting, GatewayNotification $notification, EbillEventSource $source, array $rawPayload = []): array
    {
        try {
            $outcome = DB::transaction(function () use ($setting, $notification, $source, $rawPayload): string {
                $ledger = EbillWebhookEvent::query()->create([
                    'tenant_id' => $setting->tenant_id,
                    'webhook_event_id' => $notification->eventId,
                    'event_type' => $notification->type->value,
                    'payload_hash' => hash('sha256', (string) json_encode($rawPayload !== [] ? $rawPayload : $notification->toArray())),
                    'payload' => $rawPayload !== [] ? $rawPayload : $notification->toArray(),
                    'outcome' => 'received',
                    'received_at' => now(),
                ]);

                $transmission = $this->transmissions->findBySixTransactionId($notification->sixTransactionId);

                if ($transmission === null) {
                    throw new EbillException('unknown_transaction', 'No transmission carries the transaction '.$notification->sixTransactionId.'.', 404);
                }

                $result = $this->lifecycle->applyNotification($transmission, $notification, $source);
                $ledger->forceFill(['transmission_id' => $transmission->id, 'outcome' => $result['outcome'], 'processed_at' => now()])->save();

                return $result['outcome'];
            });
        } catch (UniqueConstraintViolationException) {
            return ['event_id' => $notification->eventId, 'outcome' => self::OUTCOME_DUPLICATE];
        }

        return ['event_id' => $notification->eventId, 'outcome' => $outcome];
    }
}
