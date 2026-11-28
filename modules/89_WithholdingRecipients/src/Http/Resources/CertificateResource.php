<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WithholdingRecipients\Models\AgentReducedWithholdingCert;

/**
 * @mixin AgentReducedWithholdingCert
 */
final class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::today();
        $status = $this->valid_to->endOfDay()->lessThan($today) ? 'expired' : ($this->valid_from->greaterThan($today) ? 'upcoming' : ($this->valid_to->lessThanOrEqualTo($today->addDays(60)) ? 'expiring' : 'active'));

        return [
            'id' => $this->id,
            'recipient_profile_id' => $this->recipient_profile_id,
            'fiscal_year' => $this->fiscal_year,
            'received_on' => $this->received_on->toDateString(),
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to->toDateString(),
            'pec_message_id' => $this->pec_message_id,
            'pec_archive_reference' => $this->pec_archive_reference,
            'notes' => $this->notes,
            'status' => $status,
            'received_in_time' => $this->received_on->lessThanOrEqualTo($this->valid_from),
        ];
    }
}
