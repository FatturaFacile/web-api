<?php

declare(strict_types=1);

namespace App\Contracts\Ecommerce;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A driver that can read the delivery metadata a platform sends with its webhooks, used against replays. Platforms
 * do not sign these headers, so they only add to the deduplication on the signed body.
 */
interface IdentifiesWebhookDeliveries
{
    public function webhookDeliveryId(Request $request): ?string;

    public function webhookTriggeredAt(Request $request): ?CarbonImmutable;
}
