<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Projects\Models\Project;

final class Opportunity extends BaseEnterpriseModel
{
    protected $table = 'crm_opportunities';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expected_revenue' => 'decimal:2',
            'probability' => 'decimal:2',
            'expected_close_date' => 'date',
            'actual_close_date' => 'date',
            'discount_percentage' => 'decimal:2',
            'tags' => 'array',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OpportunityStage::class, 'stage_id');
    }

    public function salesQuote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'sales_quote_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(OpportunityLineItem::class, 'opportunity_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'related_id')->where('related_type', 'opportunity');
    }
}
