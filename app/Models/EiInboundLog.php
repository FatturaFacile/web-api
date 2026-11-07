<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EiInboundLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'provider',
        'event_type',
        'sdi_uuid',
        'fingerprint',
        'payload',
        'status',
        'error',
        'processed_at',
        'source_reference',
        'raw_content',
        'signature_verified',
        'attempts',
    ];

    /**
     * The stored message can weigh megabytes and is only read back to retry a quarantined item.
     *
     * @var list<string>
     */
    protected $hidden = ['raw_content'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'signature_verified' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
