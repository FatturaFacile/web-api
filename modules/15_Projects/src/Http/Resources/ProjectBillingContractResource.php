<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectBillingContract;

/**
 * The billing contract of a project (Mod 081); `version` is the concurrency token to send back on update.
 *
 * @mixin ProjectBillingContract
 */
final class ProjectBillingContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectBillingContract $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'project_id' => $m->project_id,
            'customer_id' => $m->customer_id,
            'billing_model' => $m->billing_model,
            'fiscal_jurisdiction' => $m->fiscal_jurisdiction,
            'currency_code' => $m->currency_code,
            'contract_total_value' => (string) $m->contract_total_value,
            'advance_payment_percent' => (string) $m->advance_payment_percent,
            'advance_payment_amount' => (string) $m->advance_payment_amount,
            'advance_recouped_amount' => (string) $m->advance_recouped_amount,
            'retention_guarantee_percent' => (string) $m->retention_guarantee_percent,
            'retention_release_rule' => $m->retention_release_rule,
            'retention_release_date' => $m->retention_release_date?->toDateString(),
            'cap_amount' => $m->cap_amount === null ? null : (string) $m->cap_amount,
            'markup_percent' => (string) $m->markup_percent,
            'rate_card_id' => $m->rate_card_id,
            'prepaid_hours_allowance' => (string) $m->prepaid_hours_allowance,
            'prepaid_amount_allowance' => (string) $m->prepaid_amount_allowance,
            'low_balance_alert_threshold_percent' => (string) $m->low_balance_alert_threshold_percent,
            'low_balance_alerted_at' => $m->low_balance_alerted_at?->toIso8601String(),
            'cig' => $m->cig,
            'cup' => $m->cup,
            'contract_reference' => $m->contract_reference,
            'contract_date' => $m->contract_date?->toDateString(),
            'billing_status' => $m->billing_status,
            'version' => (int) $m->version,
        ];
    }
}
