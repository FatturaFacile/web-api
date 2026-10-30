<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'region_translations';

    protected $fillable = [
        'tenant_id',
        'region_id',
        'locale',
        'name',
    ];

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }
}
