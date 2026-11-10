<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PrivacyDataBreach extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $table = 'privacy_data_breaches';

    protected function casts(): array
    {
        return [
            'discovered_at' => 'immutable_datetime',
            'notification_deadline_at' => 'immutable_datetime',
            'fdpic_notified_at' => 'immutable_datetime',
            'gdpr_authority_notified_at' => 'immutable_datetime',
            'subjects_notified_at' => 'immutable_datetime',
            'affected_records_count' => 'integer',
            'data_categories' => 'array',
            'requires_authority_notification' => 'boolean',
            'requires_subject_notification' => 'boolean',
        ];
    }
}
