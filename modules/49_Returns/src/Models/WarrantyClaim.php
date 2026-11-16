<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer claim on a warranty, optionally linked to the RMA that brings the goods back. The validity of the warranty
 * on the claim date is frozen on the claim when it is raised.
 *
 * open -> accepted -> resolved, and open -> rejected.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $claim_number
 * @property string $warranty_id
 * @property string|null $rma_id
 * @property Carbon $claim_date
 * @property string $description
 * @property string $status
 * @property bool $is_valid
 * @property string|null $validity_note
 * @property string|null $decision_notes
 * @property Carbon|null $decided_at
 * @property Carbon|null $resolved_at
 * @property string|null $created_by
 */
final class WarrantyClaim extends BaseEnterpriseModel
{
    public const string Open = 'open';

    public const string Accepted = 'accepted';

    public const string Rejected = 'rejected';

    public const string Resolved = 'resolved';

    /** @var list<string> */
    public const array Statuses = [self::Open, self::Accepted, self::Rejected, self::Resolved];

    protected $table = 'returns_warranty_claims';

    protected function casts(): array
    {
        return ['claim_date' => 'date', 'is_valid' => 'boolean', 'decided_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Warranty, $this>
     */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_id');
    }

    /**
     * @return BelongsTo<Rma, $this>
     */
    public function rma(): BelongsTo
    {
        return $this->belongsTo(Rma::class, 'rma_id');
    }
}
