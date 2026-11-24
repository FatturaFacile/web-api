<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class LeadDuplicateCandidate extends BaseEnterpriseModel
{
    public const array MATCH_TYPES = ['email', 'phone', 'vat', 'company_fuzzy'];

    public const array STATUSES = ['pending', 'merged', 'dismissed'];

    protected $table = 'crm_lead_duplicate_candidates';

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function candidateLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'candidate_lead_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Kind of the matched record: lead, contact or account.
     */
    public function candidateType(): string
    {
        return match (true) {
            $this->candidate_lead_id !== null => 'lead',
            $this->contact_id !== null => 'contact',
            default => 'account',
        };
    }

    public function candidateId(): ?string
    {
        return $this->candidate_lead_id ?? $this->contact_id ?? $this->account_id;
    }
}
