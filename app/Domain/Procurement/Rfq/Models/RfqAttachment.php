<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Technical specification, drawing, or BOM attached to a RequestForQuotation.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $rfq_id
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $sha256_hash
 * @property bool $is_visible_to_suppliers
 * @property Carbon|null $created_at
 * @property-read RequestForQuotation|null $rfq
 */
final class RfqAttachment extends BaseEnterpriseModel
{
    protected $table = 'procurement_rfq_attachments';

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'is_visible_to_suppliers' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RequestForQuotation, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class, 'rfq_id');
    }
}
