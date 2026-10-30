<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'regions';

    protected $fillable = [
        'tenant_id',
        'country_id',
        'code',
        'type',
    ];

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * @return HasMany<RegionTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(RegionTranslation::class, 'region_id');
    }

    protected function getTranslationModelClass(): string
    {
        return RegionTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'region_id';
    }
}
