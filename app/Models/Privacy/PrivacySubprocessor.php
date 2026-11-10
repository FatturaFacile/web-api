<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PrivacySubprocessor extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_subprocessors';

    protected function casts(): array
    {
        return [
            'is_adequate_country_nlpd' => 'boolean',
            'dpa_signed_at' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
