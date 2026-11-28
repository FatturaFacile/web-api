<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    /**
     * @return HasMany<UnitOfMeasureTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(UnitOfMeasureTranslation::class, 'unit_of_measure_id');
    }

    protected function getTranslationModelClass(): string
    {
        return UnitOfMeasureTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'unit_of_measure_id';
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
    ];
}
