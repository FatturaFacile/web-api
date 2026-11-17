<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\JobOffer;

/**
 * @mixin JobOffer
 */
final class JobOfferLetterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JobOffer $offer */
        $offer = $this->resource;

        return [
            'id' => (string) $offer->id,
            'application_id' => (string) $offer->application_id,
            'proposed_salary' => round((float) $offer->proposed_salary, 2),
            'currency' => (string) $offer->currency,
            'start_date' => $offer->start_date->toDateString(),
            'employment_type' => (string) $offer->employment_type,
            'contract_type' => (string) $offer->contract_type,
            'weekly_hours' => round((float) $offer->weekly_hours, 2),
            'probation_months' => (int) $offer->probation_months,
            'valid_until' => $offer->valid_until?->toDateString(),
            'status' => $offer->status->value,
            'signature_status' => $offer->signature_status->value,
            'signature_provider' => (string) $offer->signature_provider,
            'signature_external_id' => $offer->signature_external_id,
            'rendered_body' => (string) ($offer->rendered_body ?? ''),
            'sent_at' => $offer->sent_at?->toIso8601ZuluString(),
            'signed_at' => $offer->signed_at?->toIso8601ZuluString(),
            'declined_at' => $offer->declined_at?->toIso8601ZuluString(),
            'created_at' => $offer->created_at?->toIso8601ZuluString(),
        ];
    }
}
