<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PrivacySensitiveAccessLog extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_sensitive_access_logs';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
