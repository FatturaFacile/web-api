<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CRM\Contracts\LeadNormalizerInterface;

final class Lead extends BaseEnterpriseModel
{
    protected $table = 'crm_leads';

    protected static function booted(): void
    {
        // Keep the normalized lookup columns (E.164 phone, VAT, company key) in sync on every write path.
        static::saving(function (Lead $lead): void {
            $normalizer = app(LeadNormalizerInterface::class);

            if ($lead->isDirty('phone') || ($lead->phone_e164 === null && ! empty($lead->phone))) {
                $lead->phone_e164 = $normalizer->normalizePhone($lead->phone, $lead->country ?: null);
            }

            if ($lead->isDirty('vat_number') && $lead->vat_number !== null) {
                $lead->vat_number = $normalizer->normalizeVat($lead->vat_number);
            }

            if ($lead->isDirty('company_name') || ($lead->company_name_normalized === null && ! empty($lead->company_name))) {
                $lead->company_name_normalized = $normalizer->normalizeCompanyName($lead->company_name);
            }

            if ($lead->isDirty('email') && $lead->email !== null) {
                $lead->email = $normalizer->normalizeEmail($lead->email);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'estimated_budget' => 'decimal:2',
            'annual_revenue_estimate' => 'decimal:2',
            'employee_count' => 'integer',
            'converted_at' => 'datetime',
            'scored_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'tags' => 'array',
            'custom_fields' => 'array',
            'score_breakdown' => 'array',
        ];
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }

    public function convertedOpportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'converted_opportunity_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'related_id')->where('related_type', 'lead');
    }

    /**
     * @return HasMany<LeadActivityEvent, $this>
     */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(LeadActivityEvent::class, 'lead_id');
    }

    /**
     * @return HasMany<LeadDuplicateCandidate, $this>
     */
    public function duplicateCandidates(): HasMany
    {
        return $this->hasMany(LeadDuplicateCandidate::class, 'lead_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_lead_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Human readable one-line label (name, company, email) used by notifications and duplicate summaries.
     */
    public function displayLabel(): string
    {
        $parts = array_filter([
            $this->full_name !== '' ? $this->full_name : null,
            $this->company_name,
        ]);
        $label = implode(' - ', $parts);

        if (! empty($this->email)) {
            $label = $label !== '' ? "{$label} <{$this->email}>" : (string) $this->email;
        }

        return $label !== '' ? $label : (string) $this->id;
    }
}
