<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Procurement\Rfq\Enums\InvitationStatus;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Procurement\Models\Supplier;

/**
 * Tracks a supplier's invitation, cryptographic magic-link token hash, and bidding outcome.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $rfq_id
 * @property string $supplier_id
 * @property string $status
 * @property string|null $access_token_hash
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $invited_at
 * @property CarbonImmutable|null $first_viewed_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $responded_at
 * @property string|null $declined_reason
 * @property CarbonImmutable|null $notified_outcome_at
 * @property-read RequestForQuotation|null $rfq
 * @property-read Supplier|null $supplier
 * @property-read SupplierBid|null $bid
 */
final class RfqSupplierInvitation extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use HasImmutableAuditTrail;

    protected $table = 'procurement_rfq_suppliers';

    /**
     * Never expose token hashes in the immutable audit trail.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['access_token_hash'];

    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'immutable_datetime',
            'invited_at' => 'immutable_datetime',
            'first_viewed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'notified_outcome_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['access_token_hash']);

        return $attributes;
    }

    public function statusEnum(): InvitationStatus
    {
        return InvitationStatus::tryFrom((string) $this->status) ?? InvitationStatus::Pending;
    }

    /**
     * @return BelongsTo<RequestForQuotation, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class, 'rfq_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return HasOne<SupplierBid, $this>
     */
    public function bid(): HasOne
    {
        return $this->hasOne(SupplierBid::class, 'invitation_id');
    }
}
