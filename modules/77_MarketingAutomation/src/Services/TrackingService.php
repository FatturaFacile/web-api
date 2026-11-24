<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MarketingAutomation\Domain\Enums\ConsentEventType;
use Modules\MarketingAutomation\Domain\Enums\MessageStatus;
use Modules\MarketingAutomation\Domain\Enums\SuppressionReason;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\MarketingMessage;

/**
 * Open pixel, click redirect (UTM and attribution token preserved), one-click unsubscribe and the bounce / complaint
 * handlers. Hard bounces, complaints and unsubscribes suppress the address immediately.
 */
final readonly class TrackingService
{
    public const int SOFT_BOUNCES_BEFORE_HARD = 3;

    public function __construct(
        private SuppressionService $suppressions,
        private ConsentLedgerService $consents,
        private MetricsService $metrics,
    ) {}

    public function newToken(): string
    {
        return Str::random(40);
    }

    public function openUrl(string $token): string
    {
        return url('/api/v1/marketing/t/o/'.$token.'.gif');
    }

    public function clickUrl(string $token, string $target): string
    {
        return url('/api/v1/marketing/t/c/'.$token).'?'.http_build_query(['u' => $this->encode($target), 's' => $this->sign($target)]);
    }

    public function unsubscribeUrl(string $token): string
    {
        return url('/api/v1/marketing/u/'.$token);
    }

    public function decodeTarget(string $encoded, string $signature): ?string
    {
        $target = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($target === false || ! hash_equals($this->sign($target), $signature) || ! preg_match('#^https?://#i', $target)) {
            return null;
        }

        return $target;
    }

    /**
     * Adds the UTM campaign parameters and the attribution token to a destination.
     */
    public function withUtm(string $url, Campaign $campaign, MarketingMessage $message): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        $query += array_filter([
            'utm_source' => 'fatturafacile',
            'utm_medium' => $message->channel,
            'utm_campaign' => $campaign->utm_campaign_code ?: Str::slug($campaign->name),
            'ff_mt' => $message->token,
        ]);

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '')
            .'?'.http_build_query($query).(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public function findByToken(string $token): ?MarketingMessage
    {
        return MarketingMessage::withoutGlobalScopes()->where('token', $token)->first();
    }

    public function registerOpen(MarketingMessage $message): void
    {
        if ($message->first_opened_at === null) {
            $message->forceFill(['first_opened_at' => Carbon::now()])->save();
            $this->refresh($message);
        }
    }

    public function registerClick(MarketingMessage $message): void
    {
        if ($message->first_clicked_at === null) {
            $message->forceFill(['first_clicked_at' => Carbon::now(), 'first_opened_at' => $message->first_opened_at ?? Carbon::now()])->save();
            $this->refresh($message);
        }
    }

    /**
     * @param  array{ip_address?: ?string, user_agent?: ?string, source?: ?string}  $evidence
     */
    public function unsubscribe(MarketingMessage $message, array $evidence): void
    {
        $tenantId = (string) $message->tenant_id;
        $evidence['jurisdiction'] = $this->jurisdictionOf($tenantId, (string) $message->contact_id);
        $this->consents->unsubscribe($tenantId, (string) $message->contact_id, $evidence);
        $this->suppressions->suppress($tenantId, (string) $message->recipient, SuppressionReason::Unsubscribe, (string) $message->contact_id, (string) $message->campaign_id, $message->channel);
        $this->refresh($message);
    }

    /**
     * Provider webhook event: hard bounce, soft bounce, complaint (feedback loop) or delivered.
     *
     * @param  array{type: string, address: ?string, reference: ?string}  $event
     */
    public function registerProviderEvent(array $event, ?string $tenantId = null): ?MarketingMessage
    {
        $message = $this->locate($event, $tenantId);
        if ($message === null) {
            return null;
        }

        $tenant = (string) $message->tenant_id;
        $address = (string) ($event['address'] ?? $message->recipient);

        switch ($event['type']) {
            case 'delivered':
                $message->forceFill(['delivered_at' => $message->delivered_at ?? Carbon::now(), 'status' => $message->status === MessageStatus::Sent->value ? MessageStatus::Delivered->value : $message->status])->save();
                break;

            case 'hard_bounce':
                $this->hardBounce($message, $tenant, $address);
                break;

            case 'soft_bounce':
                $message->forceFill(['status' => MessageStatus::SoftBounced->value, 'bounced_at' => Carbon::now()])->save();
                $softs = MarketingMessage::withoutGlobalScopes()->where('tenant_id', $tenant)->where('recipient', $message->recipient)->where('status', MessageStatus::SoftBounced->value)->count();
                if ($softs >= self::SOFT_BOUNCES_BEFORE_HARD) {
                    $this->hardBounce($message, $tenant, $address);
                }
                break;

            case 'complaint':
                $message->forceFill(['status' => MessageStatus::Complained->value, 'bounced_at' => Carbon::now()])->save();
                $this->suppressions->suppress($tenant, $address, SuppressionReason::SpamComplaint, (string) $message->contact_id, (string) $message->campaign_id, $message->channel);
                $this->consents->unsubscribe($tenant, (string) $message->contact_id, ['source' => 'feedback_loop', 'jurisdiction' => $this->jurisdictionOf($tenant, (string) $message->contact_id)], ConsentEventType::SpamComplaint);
                break;

            default:
                return $message;
        }

        $this->refresh($message);

        return $message;
    }

    private function hardBounce(MarketingMessage $message, string $tenant, string $address): void
    {
        $message->forceFill(['status' => MessageStatus::HardBounced->value, 'bounced_at' => Carbon::now()])->save();
        $this->suppressions->suppress($tenant, $address, SuppressionReason::HardBounce, (string) $message->contact_id, (string) $message->campaign_id, $message->channel);
        $this->consents->append($tenant, (string) $message->contact_id, ConsentEventType::HardBounce, null, ['source' => 'provider_webhook'], ['address' => $address]);
    }

    /**
     * @param  array{type: string, address: ?string, reference: ?string}  $event
     */
    private function locate(array $event, ?string $tenantId): ?MarketingMessage
    {
        $query = MarketingMessage::withoutGlobalScopes()->when($tenantId !== null, static fn ($q) => $q->where('tenant_id', $tenantId));

        if (! empty($event['reference'])) {
            $found = (clone $query)->where('provider_reference', $event['reference'])->first();
            if ($found !== null) {
                return $found;
            }
        }

        return empty($event['address']) ? null : $query->where('recipient', $this->suppressions->normalise((string) $event['address']))->orderByDesc('sent_at')->first();
    }

    private function jurisdictionOf(string $tenantId, string $contactId): ?string
    {
        $country = DB::table('contacts')->where('tenant_id', $tenantId)->where('id', $contactId)->value('country');

        return $country === null ? null : (string) $country;
    }

    private function refresh(MarketingMessage $message): void
    {
        $campaign = Campaign::withoutGlobalScopes()->find($message->campaign_id);
        if ($campaign !== null) {
            $this->metrics->recompute($campaign);
        }
    }

    private function encode(string $target): string
    {
        return rtrim(strtr(base64_encode($target), '+/', '-_'), '=');
    }

    private function sign(string $target): string
    {
        $secret = (string) (config('marketing_automation.tracking_secret') ?: config('app.key'));

        return hash_hmac('sha256', $target, $secret);
    }
}
