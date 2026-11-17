<?php

declare(strict_types=1);

namespace App\Models\RecurringContracts;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $index_type
 * @property string $period
 * @property string $index_value
 * @property CarbonInterface|null $published_at
 * @property string|null $source_reference
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PriceIndexRate extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'price_index_rates';

    protected $fillable = [
        'tenant_id',
        'index_type',
        'period',
        'index_value',
        'published_at',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'index_value' => 'decimal:4',
            'published_at' => 'date',
        ];
    }
}
