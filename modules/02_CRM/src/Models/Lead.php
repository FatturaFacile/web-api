<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A prospect captured before qualification; converted into an account, a contact of the party master and an
 * optional opportunity.
 *
 * @property array{model?: string, items?: list<array{rule: string, points: int, reason: string}>}|null $score_breakdown
 * @property array<string, mixed>|null $custom_fields
 * @property Carbon|null $score_calculated_at
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Lead extends BaseEnterpriseModel
{
    protected $table = 'crm_leads';

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'estimated_budget' => 'decimal:2',
            'converted_at' => 'datetime',
            'tags' => 'array',
            'custom_fields' => 'array',
            'score_breakdown' => 'array',
            'score_calculated_at' => 'datetime',
        ];
    }

    /**
     * Calculated grid columns: `display_name` is the text the lead list shows (full name, else the company name).
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'display_name' => "COALESCE(NULLIF(TRIM(COALESCE(crm_leads.first_name, '') || ' ' || COALESCE(crm_leads.last_name, '')), ''), crm_leads.company_name)",
        ];
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
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

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
