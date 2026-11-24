<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CRM\Support\LocalizedLabel;

/**
 * A sales pipeline, differentiated by business line and/or geographic market, with its own ordered stages.
 *
 * @property string $code
 * @property string $name
 * @property array<string, string>|null $translations
 * @property bool $is_default
 * @property bool $is_active
 */
final class Pipeline extends BaseEnterpriseModel
{
    protected $table = 'crm_pipelines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'sequence' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<OpportunityStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(OpportunityStage::class, 'pipeline_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'pipeline_id');
    }

    public function label(): string
    {
        return LocalizedLabel::resolve($this->translations, (string) $this->name);
    }
}
