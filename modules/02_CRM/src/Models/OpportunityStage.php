<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CRM\Support\LocalizedLabel;

/**
 * @property string $code
 * @property string $name
 * @property string|null $pipeline_id
 * @property string $forecast_category
 * @property array<string, string>|null $translations
 */
final class OpportunityStage extends BaseEnterpriseModel
{
    protected $table = 'crm_opportunity_stages';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'default_probability' => 'decimal:2',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'stale_days' => 'integer',
            'is_active' => 'boolean',
            'translations' => 'array',
        ];
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'stage_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function label(): string
    {
        return LocalizedLabel::resolve($this->translations, (string) $this->name);
    }
}
