<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Campaign extends BaseEnterpriseModel
{
    protected $table = 'crm_campaigns';

    protected $keyType = 'string';

    public $incrementing = false;

    public function __construct(array $attributes = [])
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Str::uuid7();
        }

        parent::__construct($attributes);
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'budgeted_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'expected_revenue' => 'decimal:2',
            'actual_revenue' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function campaignType(): BelongsTo
    {
        return $this->belongsTo(CampaignType::class, 'campaign_type_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CampaignMember::class, 'campaign_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'campaign_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'campaign_id');
    }

    public function calculateRoi(): float
    {
        if ((float) $this->actual_cost <= 0.0) {
            return 0.0;
        }

        return round((((float) $this->actual_revenue - (float) $this->actual_cost) / (float) $this->actual_cost) * 100.0, 2);
    }
}
