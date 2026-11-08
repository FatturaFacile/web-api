<?php

declare(strict_types=1);

namespace Modules\Procurement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A qualification document of a supplier (certification, insurance, registration extract...) with its expiry date.
 * The document types and which of them are mandatory are decided by the company.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string $document_type
 * @property string|null $reference
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property bool $is_mandatory
 * @property string|null $attachment_id
 * @property string|null $notes
 * @property string|null $created_by
 */
final class SupplierDocument extends BaseEnterpriseModel
{
    protected $table = 'supplier_documents';

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'is_mandatory' => 'boolean',
        ];
    }

    /**
     * Whether the document is past its expiry date on the given day (default today).
     */
    public function isExpired(?string $onDate = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->toDateString() < ($onDate ?? now()->toDateString());
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
