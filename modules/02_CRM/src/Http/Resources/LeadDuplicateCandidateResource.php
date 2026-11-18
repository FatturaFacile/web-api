<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadDuplicateCandidate;

/**
 * @mixin LeadDuplicateCandidate
 */
final class LeadDuplicateCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead' => $this->lead instanceof Lead ? new LeadResource($this->lead) : null,
            'candidate' => [
                'type' => $this->candidateType(),
                'id' => $this->candidateId(),
                'summary' => $this->candidateSummary(),
            ],
            'match_type' => $this->match_type,
            'confidence' => round((float) $this->confidence, 4),
            'status' => $this->status,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function candidateSummary(): ?string
    {
        $type = $this->candidateType();

        if ($type === 'lead') {
            $lead = $this->candidateLead;

            return $lead instanceof Lead ? $lead->displayLabel() : null;
        }

        if ($type === 'contact') {
            $contact = $this->contact;
            if (! $contact instanceof Contact) {
                return null;
            }

            $name = trim(implode(' ', array_filter([$contact->first_name, $contact->last_name])));
            $parts = array_filter([$name !== '' ? $name : null, $contact->company_name, $contact->email ? "<{$contact->email}>" : null, $contact->vat_number]);

            return implode(' ', $parts);
        }

        $account = $this->account;

        return $account instanceof Account ? (string) $account->name : null;
    }
}
