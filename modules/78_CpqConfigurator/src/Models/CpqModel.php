<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configurable product model of the technical-commercial CPQ: header, price and cost basis, the guided steps, the
 * visual base of the preview and the commercial texts of the proposal. A change that alters the outcome of a published
 * model raises its version, so an instance says which version of the rules produced it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property string $currency
 * @property string $base_price
 * @property string $base_cost
 * @property int $base_lead_time_days
 * @property string|null $target_margin_percent
 * @property string $max_discount_percent
 * @property string $part_number_prefix
 * @property int $part_number_sequence
 * @property list<array{code: string, title: array<string, string>, description?: array<string, string>}>|null $steps
 * @property array<string, mixed>|null $visual_base
 * @property array<string, mixed>|null $proposal_texts
 * @property string $status
 * @property int $version
 * @property Carbon|null $published_at
 */
final class CpqModel extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string Published = 'published';

    public const string Archived = 'archived';

    protected $table = 'cpq_models';

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'base_price' => 'decimal:4',
            'base_cost' => 'decimal:4',
            'base_lead_time_days' => 'integer',
            'target_margin_percent' => 'decimal:2',
            'max_discount_percent' => 'decimal:2',
            'part_number_sequence' => 'integer',
            'steps' => 'array',
            'visual_base' => 'array',
            'proposal_texts' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CpqCharacteristic, $this>
     */
    public function characteristics(): HasMany
    {
        return $this->hasMany(CpqCharacteristic::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<CpqConstraintRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(CpqConstraintRule::class, 'model_id')->orderBy('priority')->orderBy('code');
    }

    /**
     * @return HasMany<CpqSuperBomMapping, $this>
     */
    public function bomMappings(): HasMany
    {
        return $this->hasMany(CpqSuperBomMapping::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<CpqRoutingMapping, $this>
     */
    public function routingMappings(): HasMany
    {
        return $this->hasMany(CpqRoutingMapping::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<CpqPricingFormula, $this>
     */
    public function pricingFormulas(): HasMany
    {
        return $this->hasMany(CpqPricingFormula::class, 'model_id')->orderBy('sort_order')->orderBy('code');
    }

    /**
     * @return HasMany<CpqConfiguredInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(CpqConfiguredInstance::class, 'model_id');
    }

    public function isEditable(): bool
    {
        return $this->status !== self::Archived;
    }
}
