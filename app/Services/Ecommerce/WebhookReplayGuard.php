<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Contracts\Ecommerce\EcommerceDriverInterface;
use App\Contracts\Ecommerce\IdentifiesWebhookDeliveries;
use App\Models\EcommerceChannel;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;

/**
 * Replay protection for signed webhooks. The platforms sign the body only, so a captured delivery could be sent again
 * as is. A delivery is therefore accepted once per channel: the hash of its signed body (and the delivery id when the
 * platform sends one: WooCommerce `X-WC-Webhook-Delivery-ID`, Shopify `X-Shopify-Webhook-Id`) is claimed in the cache
 * for a configurable time, and a delivery whose platform timestamp (Shopify `X-Shopify-Triggered-At`) is older than
 * the tolerance is refused. A claim is released when processing fails, so the platform can retry.
 *
 * The id and timestamp headers are not signed: the body hash is the protection that holds against an attacker.
 */
final class WebhookReplayGuard
{
    public const string ACCEPTED = 'accepted';

    public const string REPLAYED = 'replayed';

    public const string EXPIRED = 'expired';

    public function __construct(private readonly Repository $cache) {}

    /**
     * Claim the delivery before it is processed.
     *
     * @return array{status: string, keys: list<string>}
     */
    public function claim(EcommerceChannel $channel, EcommerceDriverInterface $driver, Request $request): array
    {
        $deliveryId = null;

        if ($driver instanceof IdentifiesWebhookDeliveries) {
            $triggeredAt = $driver->webhookTriggeredAt($request);
            $tolerance = max(60, (int) config('security.webhooks.tolerance_seconds', 172800));

            if ($triggeredAt !== null && abs(now()->getTimestamp() - $triggeredAt->getTimestamp()) > $tolerance) {
                return ['status' => self::EXPIRED, 'keys' => []];
            }

            $deliveryId = $driver->webhookDeliveryId($request);
        }

        $prefix = "ecommerce-webhook:{$channel->id}:";
        $keys = [$prefix.'body:'.hash('sha256', $request->getContent())];

        if ($deliveryId !== null) {
            $keys[] = $prefix.'delivery:'.hash('sha256', $deliveryId);
        }

        $ttl = max(60, (int) config('security.webhooks.dedupe_ttl_seconds', 180000));
        $claimed = [];

        foreach ($keys as $key) {
            if (! $this->cache->add($key, now()->getTimestamp(), $ttl)) {
                $this->release($claimed);

                return ['status' => self::REPLAYED, 'keys' => []];
            }

            $claimed[] = $key;
        }

        return ['status' => self::ACCEPTED, 'keys' => $claimed];
    }

    /**
     * Forget a claim, after a delivery that could not be processed.
     *
     * @param  list<string>  $keys
     */
    public function release(array $keys): void
    {
        foreach ($keys as $key) {
            $this->cache->forget($key);
        }
    }
}
