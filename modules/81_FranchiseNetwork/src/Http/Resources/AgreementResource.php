<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;

/**
 * @mixin FranchiseAgreement
 */
final class AgreementResource extends JsonResource
{
    /**
     * @param  list<array<string, mixed>>  $findings  compliance findings of the contract text
     * @param  list<array<string, mixed>>  $alerts  alerts of the day (renewal notice, guarantee, expiry)
     */
    public function __construct(FranchiseAgreement $resource, private readonly array $findings = [], private readonly array $alerts = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'store_code' => $this->store?->code,
            'store_name' => $this->store?->name,
            'formula_id' => $this->formula_id,
            'agreement_number' => $this->agreement_number,
            'status' => $this->status->value,
            'jurisdiction' => $this->jurisdiction->value,
            'disclosure_delivered_on' => $this->disclosure_delivered_on?->toDateString(),
            'signed_on' => $this->signed_on?->toDateString(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'term_months' => $this->term_months,
            'auto_renew' => $this->auto_renew,
            'renewal_notice_days' => $this->renewal_notice_days,
            'exclusivity_zone' => $this->exclusivity_zone,
            'entry_fee' => $this->entry_fee,
            'entry_fee_deferral_months' => $this->entry_fee_deferral_months,
            'min_purchase_amount' => $this->min_purchase_amount,
            'min_purchase_source' => $this->min_purchase_source,
            'approved_supplier_ids' => $this->approved_supplier_ids,
            'guarantee_type' => $this->guarantee_type->value,
            'guarantee_amount' => $this->guarantee_amount,
            'guarantee_reference' => $this->guarantee_reference,
            'guarantee_expires_on' => $this->guarantee_expires_on?->toDateString(),
            'payment_method' => $this->payment_method->value,
            'mandate_reference' => $this->mandate_reference,
            'mandate_signed_on' => $this->mandate_signed_on?->toDateString(),
            'currency' => $this->currency,
            'non_binding_pricing' => $this->non_binding_pricing,
            'notes' => $this->when(! $request->routeIs('franchise-network.portal.*'), $this->notes),
            'findings' => $this->findings,
            'alerts' => $this->alerts,
            'deferrals' => DeferralResource::collection($this->whenLoaded('deferrals')),
        ];
    }
}
