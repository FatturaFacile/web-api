<?php

declare(strict_types=1);

namespace Modules\SDI\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\SDI\Enums\SdiNotificationType;

/**
 * @property SdiNotificationType $notification_type
 * @property Carbon|null $received_at
 * @property Carbon|null $processed_at
 */
class SdiNotification extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'fpa_document_id', 'notification_type', 'sdi_identifier', 'sdi_message_id', 'outcome_code',
        'error_codes', 'raw_xml_path', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'notification_type' => SdiNotificationType::class,
            'error_codes' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FpaDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(FpaDocument::class, 'fpa_document_id');
    }
}
