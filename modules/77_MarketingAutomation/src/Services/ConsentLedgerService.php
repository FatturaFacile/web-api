<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MarketingAutomation\Domain\Enums\ConsentEventType;
use Modules\MarketingAutomation\Models\ConsentEvent;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Services\Privacy\PrivacyStrategyResolver;

/**
 * Granular consent state per contact plus the immutable audit trail of every change.
 */
final readonly class ConsentLedgerService
{
    public function __construct(private PrivacyStrategyResolver $strategies) {}

    public function current(string $tenantId, string $contactId): ?MarketingConsent
    {
        return MarketingConsent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('contact_id', $contactId)->first();
    }

    /**
     * @param  array{marketing_opt_in?: bool, profiling_opt_in?: bool, soft_opt_in_eligible?: bool, channel_preferences?: array<string, bool>, locale?: string, double_opt_in_confirmed?: bool}  $changes
     * @param  array{ip_address?: ?string, user_agent?: ?string, source?: ?string, jurisdiction?: ?string}  $evidence
     */
    public function record(string $tenantId, string $contactId, array $changes, array $evidence = [], ?string $actorUserId = null): MarketingConsent
    {
        return DB::transaction(function () use ($tenantId, $contactId, $changes, $evidence, $actorUserId): MarketingConsent {
            $consent = $this->current($tenantId, $contactId)
                ?? MarketingConsent::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'contact_id' => $contactId, 'created_by' => $actorUserId]);

            $before = $consent->only(['marketing_opt_in', 'profiling_opt_in', 'soft_opt_in_eligible', 'double_opt_in_confirmed']);
            $consent->fill(array_intersect_key($changes, array_flip(['marketing_opt_in', 'profiling_opt_in', 'soft_opt_in_eligible', 'channel_preferences', 'locale', 'double_opt_in_confirmed'])));

            // Giving marketing consent again lifts an earlier unsubscribe; the ledger keeps both facts.
            $resubscribed = ($changes['marketing_opt_in'] ?? false) === true && $consent->unsubscribed_at !== null;
            if ($resubscribed) {
                $consent->unsubscribed_at = null;
            }
            $consent->updated_by = $actorUserId;
            $consent->save();

            $strategy = $this->strategies->forJurisdiction($evidence['jurisdiction'] ?? null);

            foreach ($this->eventsFor($before, $consent, $resubscribed) as $type) {
                $this->append($tenantId, $contactId, $type, $strategy->code(), $evidence, ['before' => $before]);
            }

            return $consent;
        });
    }

    /**
     * Records the unsubscribe (one-click, link or complaint) and switches every promotional permission off.
     *
     * @param  array{ip_address?: ?string, user_agent?: ?string, source?: ?string, jurisdiction?: ?string}  $evidence
     */
    public function unsubscribe(string $tenantId, string $contactId, array $evidence = [], ConsentEventType $type = ConsentEventType::Unsubscribe): MarketingConsent
    {
        return DB::transaction(function () use ($tenantId, $contactId, $evidence, $type): MarketingConsent {
            $consent = $this->current($tenantId, $contactId)
                ?? MarketingConsent::withoutGlobalScopes()->create(['tenant_id' => $tenantId, 'contact_id' => $contactId]);

            $alreadyOff = $consent->unsubscribed_at !== null;
            $consent->forceFill([
                'marketing_opt_in' => false,
                'soft_opt_in_eligible' => false,
                'unsubscribed_at' => $consent->unsubscribed_at ?? Carbon::now(),
            ])->save();

            if (! $alreadyOff) {
                $strategy = $this->strategies->forJurisdiction($evidence['jurisdiction'] ?? null);
                $this->append($tenantId, $contactId, $type, $strategy->code(), $evidence, []);
            }

            return $consent;
        });
    }

    /**
     * @param  array{ip_address?: ?string, user_agent?: ?string, source?: ?string}  $evidence
     * @param  array<string, mixed>  $details
     */
    public function append(string $tenantId, string $contactId, ConsentEventType $type, ?string $strategyCode, array $evidence, array $details): ConsentEvent
    {
        return ConsentEvent::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'event_type' => $type->value,
            'lawful_basis' => $this->lawfulBasis($type),
            'strategy_code' => $strategyCode,
            'source' => $evidence['source'] ?? null,
            'ip_address' => $evidence['ip_address'] ?? null,
            'user_agent' => isset($evidence['user_agent']) ? mb_substr($evidence['user_agent'], 0, 500) : null,
            'details' => $details,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @return list<ConsentEventType>
     */
    private function eventsFor(array $before, MarketingConsent $after, bool $resubscribed): array
    {
        $events = [];
        if ($resubscribed) {
            $events[] = ConsentEventType::Resubscribe;
        }
        if (! ($before['marketing_opt_in'] ?? false) && $after->marketing_opt_in) {
            $events[] = ConsentEventType::OptIn;
        }
        if (($before['marketing_opt_in'] ?? false) && ! $after->marketing_opt_in) {
            $events[] = ConsentEventType::OptOut;
        }
        if (! ($before['double_opt_in_confirmed'] ?? false) && $after->double_opt_in_confirmed) {
            $events[] = ConsentEventType::DoubleOptInConfirmed;
        }
        if (! ($before['profiling_opt_in'] ?? false) && $after->profiling_opt_in) {
            $events[] = ConsentEventType::ProfilingOptIn;
        }
        if (($before['profiling_opt_in'] ?? false) && ! $after->profiling_opt_in) {
            $events[] = ConsentEventType::ProfilingOptOut;
        }
        if (! ($before['soft_opt_in_eligible'] ?? false) && $after->soft_opt_in_eligible) {
            $events[] = ConsentEventType::SoftOptInEligible;
        }

        return $events;
    }

    private function lawfulBasis(ConsentEventType $type): string
    {
        return match ($type) {
            ConsentEventType::SoftOptInEligible => 'legitimate_interest_soft_opt_in',
            ConsentEventType::Unsubscribe, ConsentEventType::OptOut, ConsentEventType::HardBounce, ConsentEventType::SpamComplaint, ConsentEventType::ProfilingOptOut => 'objection',
            default => 'consent',
        };
    }
}
