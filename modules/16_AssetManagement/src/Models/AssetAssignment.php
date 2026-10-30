<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Models;

use Database\Factories\AssetAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class AssetAssignment extends Model
{
    /** @use HasFactory<AssetAssignmentFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'eam_asset_assignments';

    protected $keyType = 'string';

    public $incrementing = false;

    public function __construct(array $attributes = [])
    {
        if (! isset($attributes['id'])) {
            $attributes['id'] = (string) Str::uuid7();
        }

        parent::__construct($attributes);

        if (! isset($this->attributes['id'])) {
            $this->attributes['id'] = $attributes['id'];
        }
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'date',
            'expected_return_at' => 'date',
            'returned_at' => 'date',
            'metadata' => 'array',
        ];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    protected static function newFactory(): AssetAssignmentFactory
    {
        return AssetAssignmentFactory::new();
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    /**
     * @return BelongsTo<AssetLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }
}
