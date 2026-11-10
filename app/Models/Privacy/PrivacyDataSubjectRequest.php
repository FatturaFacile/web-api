<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PrivacyDataSubjectRequest extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_data_subject_requests';

    protected function casts(): array
    {
        return [
            'deadline_at' => 'immutable_datetime',
            'extended_until' => 'immutable_datetime',
            'legal_holds' => 'array',
            'erasure_summary' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
