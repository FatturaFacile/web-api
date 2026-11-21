<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\DataResidency\Domain\Enums\TransferDecision;
use Modules\DataResidency\Domain\Events\UnauthorizedCrossBorderTransferBlockedEvent;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Domain\ValueObjects\AdequacyRegistry;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\DataResidency\Infrastructure\Models\CrossBorderTransferRule;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * The egress firewall: every outbound webhook, connector, external AI call and export of a tenant with a residency policy
 * passes here. The destination country is resolved and judged against the tenant's rule, the statutory baseline row or
 * the built-in adequacy lists; a destination that cannot be verified is treated as non-adequate. A block is recorded as an
 * immutable compliance alert and answers HTTP 451.
 */
final class CrossBorderEgressGuard
{
    public function __construct(
        private readonly ResidencyPolicyResolver $policies,
        private readonly EgressCountryResolver $countries,
    ) {}

    /**
     * The verdict on a destination without side effects.
     *
     * @return array{country: string|null, decision: TransferDecision, allowed: bool, reason: string|null, legal_basis: string|null}
     */
    public function evaluate(TenantDataResidencyPolicy $policy, string $target, ?string $explicitCountry = null): array
    {
        $country = $this->countries->resolve($target, $explicitCountry);

        if ($country === null) {
            return ['country' => null, 'decision' => TransferDecision::Blocked, 'allowed' => false, 'reason' => 'The destination country could not be verified.', 'legal_basis' => null];
        }

        $legalBasis = AdequacyRegistry::legalBasis($policy->jurisdiction_zone, $country);

        if ($policy->jurisdiction_zone->allowsCountry($country)) {
            return ['country' => $country, 'decision' => TransferDecision::Allowed, 'allowed' => true, 'reason' => null, 'legal_basis' => $legalBasis];
        }

        $decision = $this->ruleDecision($policy, $country) ?? AdequacyRegistry::decisionFor($policy->jurisdiction_zone, $country);
        $allowed = $decision === TransferDecision::Allowed || ($decision === TransferDecision::RequiresSccTia && ! $policy->enforce_strict_egress_block);

        return ['country' => $country, 'decision' => $decision, 'allowed' => $allowed, 'reason' => $allowed ? null : "Transfer to {$country} is {$decision->value} under {$policy->jurisdiction_zone->value}.", 'legal_basis' => $legalBasis];
    }

    /**
     * @throws CrossBorderTransferBlockedException
     */
    public function assertAllowed(string $tenantId, ?string $legalEntityId, string $purpose, string $target, ?string $explicitCountry = null): void
    {
        $policy = $this->policies->resolve($tenantId, $legalEntityId);

        if ($policy === null) {
            return;
        }

        $verdict = $this->evaluate($policy, $target, $explicitCountry);

        if ($verdict['allowed']) {
            return;
        }

        if ($verdict['country'] === null) {
            $this->block($policy, null, $purpose, $target, TransferDecision::Blocked, (string) $verdict['reason']);
        }

        try {
            $policy->validateOutboundEndpoint($verdict['country'], $purpose, $verdict['decision']);
        } catch (CrossBorderTransferBlockedException $exception) {
            $this->record($policy, $verdict['country'], $purpose, $target, $verdict['decision'], $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * The decision of the matrix: the tenant's own rule, else the global statutory row, else null (built-in lists apply).
     */
    public function ruleDecision(TenantDataResidencyPolicy $policy, string $country): ?TransferDecision
    {
        $rules = CrossBorderTransferRule::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('destination_country_iso2', $country)
            ->where('source_jurisdiction', $policy->jurisdiction_zone->value)
            ->where(static fn ($query) => $query->where('tenant_id', $policy->tenant_id)->orWhereNull('tenant_id'))
            ->get();

        return ($rules->firstWhere('tenant_id', $policy->tenant_id) ?? $rules->first())?->decision_status;
    }

    private function block(TenantDataResidencyPolicy $policy, ?string $country, string $purpose, string $target, TransferDecision $decision, string $reason): never
    {
        event(new UnauthorizedCrossBorderTransferBlockedEvent((string) $policy->tenant_id, $policy->legal_entity_id, $country, $purpose, $reason));
        $this->record($policy, $country, $purpose, $target, $decision, $reason);

        throw new CrossBorderTransferBlockedException($reason);
    }

    private function record(TenantDataResidencyPolicy $policy, ?string $country, string $purpose, string $target, TransferDecision $decision, string $reason): void
    {
        CrossBorderBlockedAttempt::query()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $policy->tenant_id,
            'legal_entity_id' => $policy->legal_entity_id,
            'jurisdiction_zone' => $policy->jurisdiction_zone->value,
            'destination_country_iso2' => $country,
            'purpose' => Str::limit($purpose, 64, ''),
            'target' => Str::limit(self::redact($target), 255, ''),
            'decision_status' => $decision->value,
            'reason' => Str::limit($reason, 255, ''),
            'occurred_at' => Carbon::now(),
        ]);
    }

    /** The alert keeps the host and the path, never a query string that may carry tokens. */
    private static function redact(string $target): string
    {
        return explode('?', $target)[0];
    }

    /** Whether the destination is judged adequate by the baseline alone (used by the validation endpoint). */
    public function baseline(TenantDataResidencyPolicy $policy, string $country): TransferDecision
    {
        return AdequacyRegistry::decisionFor($policy->jurisdiction_zone, $country);
    }
}
