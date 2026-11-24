<?php

declare(strict_types=1);

namespace Enterprise\FeatureFlags\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

final class FeatureFlag extends Model
{
    use HasUuidV7;

    protected $table = 'enterprise_feature_flags';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'payload_config' => 'array',
        ];
    }
}
