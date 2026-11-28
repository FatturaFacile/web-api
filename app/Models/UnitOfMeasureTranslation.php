<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 */
class UnitOfMeasureTranslation extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory;

    protected $table = 'unit_of_measure_translations';

    protected $fillable = [
        'tenant_id',
        'unit_of_measure_id',
        'locale',
        'name',
        'symbol',
    ];

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
