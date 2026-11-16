<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * A document sent by or for a supplier against a requirement. Once accepted it is mirrored in the qualification
 * documents of the procurement module (supplier_document_id), which keeps the qualification expiry rules.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string|null $onboarding_id
 * @property string $requirement_id
 * @property string|null $supplier_document_id
 * @property string $status
 * @property string|null $reference
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $file_path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string $submitted_via
 * @property string|null $submitted_by
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string|null $notes
 * @property DocumentRequirement|null $requirement
 * @property Supplier|null $supplier
 */
final class SupplierDocumentSubmission extends BaseEnterpriseModel
{
    public const string Submitted = 'submitted';

    public const string Accepted = 'accepted';

    public const string Rejected = 'rejected';

    public const string Superseded = 'superseded';

    public const array Statuses = [self::Submitted, self::Accepted, self::Rejected, self::Superseded];

    protected $table = 'sp_document_submissions';

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function isExpired(?string $onDate = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->toDateString() < ($onDate ?? now()->toDateString());
    }

    /**
     * @return BelongsTo<DocumentRequirement, $this>
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(DocumentRequirement::class, 'requirement_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
