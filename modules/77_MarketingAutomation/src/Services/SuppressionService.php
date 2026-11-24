<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Modules\MarketingAutomation\Domain\Enums\SuppressionReason;
use Modules\MarketingAutomation\Models\Suppression;

/**
 * Instant suppression list: hard bounces, spam complaints (FBL) and one-click unsubscribes.
 */
final class SuppressionService
{
    public function suppress(string $tenantId, string $address, SuppressionReason $reason, ?string $contactId = null, ?string $campaignId = null, string $channel = 'email'): Suppression
    {
        $address = $this->normalise($address);

        $existing = Suppression::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('channel', $channel)->where('address', $address)->first();

        if ($existing !== null) {
            // A complaint is the strongest signal and keeps its reason; otherwise the first reason stands.
            if ($reason === SuppressionReason::SpamComplaint && $existing->reason !== SuppressionReason::SpamComplaint->value) {
                $existing->forceFill(['reason' => $reason->value])->save();
            }

            return $existing;
        }

        return Suppression::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'address' => $address,
            'channel' => $channel,
            'reason' => $reason->value,
            'campaign_id' => $campaignId,
            'suppressed_at' => Carbon::now(),
        ]);
    }

    public function isSuppressed(string $tenantId, string $address, string $channel = 'email'): bool
    {
        return Suppression::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('channel', $channel)->where('address', $this->normalise($address))
            ->exists();
    }

    /**
     * @return array<string, true> suppressed addresses of the tenant, as set
     */
    public function addressSet(string $tenantId, string $channel = 'email'): array
    {
        $set = [];
        foreach (Suppression::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('channel', $channel)->pluck('address') as $address) {
            $set[(string) $address] = true;
        }

        return $set;
    }

    public function normalise(string $address): string
    {
        return mb_strtolower(trim($address));
    }
}
