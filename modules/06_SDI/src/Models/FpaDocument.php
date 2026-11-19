<?php

declare(strict_types=1);

namespace Modules\SDI\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Invoice;
use Database\Factories\FpaDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\SDI\Enums\FpaDirection;
use Modules\SDI\Enums\FpaStatus;

/**
 * @property FpaStatus $status
 * @property FpaDirection $direction
 * @property Carbon|null $document_date
 * @property Carbon|null $rejected_at
 * @property Carbon|null $resubmission_deadline_at
 * @property Carbon|null $transmitted_at
 */
class FpaDocument extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'passive_invoice_id', 'parent_fpa_document_id', 'direction', 'format_transmission',
        'document_type', 'document_number', 'document_date', 'sdi_identifier', 'file_name', 'transmission_progressive', 'recipient_code', 'recipient_pec',
        'status', 'xml_storage_path', 'signed_storage_path', 'sha256_hash', 'validation_errors', 'transmitted_at',
        'rejected_at', 'resubmission_deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => FpaDirection::class,
            'status' => FpaStatus::class,
            'document_date' => 'date',
            'validation_errors' => 'array',
            'transmitted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'resubmission_deadline_at' => 'datetime',
        ];
    }

    protected static function newFactory(): FpaDocumentFactory
    {
        return FpaDocumentFactory::new();
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_fpa_document_id');
    }

    /** @return HasMany<SdiNotification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(SdiNotification::class)->orderBy('received_at');
    }
}
