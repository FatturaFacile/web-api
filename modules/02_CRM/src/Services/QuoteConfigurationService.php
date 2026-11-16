<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CRM\Contracts\ProductConfigurationLookup;
use Modules\CRM\Models\DiscountThreshold;
use Modules\CRM\Models\Partner;
use Modules\CRM\Models\QuoteConfiguration;

/**
 * CPQ quote configurations: pricing from the rules (and from a saved product configuration of the configurator when
 * given), and the discount approval flow. draft -> pending_approval -> approved | rejected; a quote whose total
 * discount stays under every threshold is approved on submission. A rejected quote can be edited and submitted again.
 */
final class QuoteConfigurationService
{
    public function __construct(
        private readonly QuotePricingService $pricing,
        private readonly ProductConfigurationLookup $configurations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): QuoteConfiguration
    {
        return QuoteConfiguration::create([
            'tenant_id' => $tenantId,
            'status' => 'draft',
            ...$data,
            ...$this->priced($tenantId, $data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QuoteConfiguration $quote, array $data): QuoteConfiguration
    {
        if (! in_array($quote->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a draft or rejected quote can be changed.']);
        }

        $merged = [...$quote->only(['product_configuration_id', 'partner_id', 'quantity', 'unit_price', 'currency', 'extra_discount_percent']), ...$data];
        $quote->fill([...$data, ...$this->priced((string) $quote->tenant_id, $merged), 'status' => 'draft'])->save();

        return $quote;
    }

    /**
     * Recomputes the price (the rules and thresholds may have changed) and asks for approval when a threshold is reached.
     */
    public function submit(QuoteConfiguration $quote): QuoteConfiguration
    {
        if (! in_array($quote->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'The quote is '.$quote->status.' and cannot be submitted.']);
        }

        $priced = $this->priced((string) $quote->tenant_id, $quote->only(['product_configuration_id', 'partner_id', 'quantity', 'unit_price', 'currency', 'extra_discount_percent']));
        $required = $priced['required_approver_role'];
        $quote->forceFill([...$priced, 'status' => $required === null ? 'approved' : 'pending_approval', 'decision_reason' => null])->save();

        return $quote;
    }

    public function approve(QuoteConfiguration $quote, string $userId, ?string $userRole): QuoteConfiguration
    {
        $this->assertPending($quote);

        if ($this->rank($userRole) < $this->rank($quote->required_approver_role)) {
            throw ValidationException::withMessages(['status' => 'This discount needs the approval of '.$quote->required_approver_role.' or higher.']);
        }

        return $this->decide($quote, 'approved', $userId, null);
    }

    public function reject(QuoteConfiguration $quote, string $userId, ?string $userRole, string $reason): QuoteConfiguration
    {
        $this->assertPending($quote);

        if ($this->rank($userRole) < $this->rank($quote->required_approver_role)) {
            throw ValidationException::withMessages(['status' => 'This discount needs the decision of '.$quote->required_approver_role.' or higher.']);
        }

        return $this->decide($quote, 'rejected', $userId, $reason);
    }

    /**
     * Price of a quote without saving it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, array $data): array
    {
        return $this->priced($tenantId, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{quantity: string, unit_price: string, currency: string, rule_discount_percent: string, extra_discount_percent: string, discount_percent: string, net_total: string, required_approver_role: string|null}
     */
    private function priced(string $tenantId, array $data): array
    {
        $quantity = (string) ($data['quantity'] ?? '1');
        $unitPrice = (string) ($data['unit_price'] ?? '0');
        $currency = (string) ($data['currency'] ?? 'EUR');

        if (! empty($data['product_configuration_id'])) {
            $configuration = $this->configurations->findQuotable($tenantId, (string) $data['product_configuration_id']);

            if ($configuration === null) {
                throw ValidationException::withMessages(['product_configuration_id' => 'The product configuration does not exist or is not ready to be quoted.']);
            }

            $quantity = (string) ($data['quantity'] ?? $configuration['quantity']);
            $unitPrice = bccomp($configuration['quantity'], '0', 3) > 0 ? bcdiv($configuration['total_net'], $configuration['quantity'], 4) : $configuration['total_net'];
            $currency = $configuration['currency'];
        }

        $tierCode = ! empty($data['partner_id'])
            ? Partner::query()->where('tenant_id', $tenantId)->with('tier')->find($data['partner_id'])?->tier?->code
            : null;
        $price = $this->pricing->price($tenantId, $quantity, $unitPrice, (string) ($data['extra_discount_percent'] ?? '0'), $tierCode);
        unset($price['rule_name']);

        return ['quantity' => $quantity, 'unit_price' => $unitPrice, 'currency' => $currency, ...$price];
    }

    private function assertPending(QuoteConfiguration $quote): void
    {
        if ($quote->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => 'The quote is '.$quote->status.' and has nothing to decide.']);
        }
    }

    private function rank(?string $role): int
    {
        $position = array_search($role, DiscountThreshold::ApproverRoles, true);

        return $position === false ? 0 : $position + 1;
    }

    private function decide(QuoteConfiguration $quote, string $status, string $userId, ?string $reason): QuoteConfiguration
    {
        return DB::transaction(function () use ($quote, $status, $userId, $reason): QuoteConfiguration {
            $quote->forceFill(['status' => $status, 'decided_by_user_id' => $userId, 'decided_at' => now(), 'decision_reason' => $reason])->save();

            return $quote;
        });
    }
}
