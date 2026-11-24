<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceMapping extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'entity_type',
        'local_id',
        'remote_id',
        'remote_sku',
        'extra_data',
    ];

    protected function casts(): array
    {
        return [
            'extra_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EcommerceChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(EcommerceChannel::class);
    }
}
