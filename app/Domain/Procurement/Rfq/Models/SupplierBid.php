<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Procurement\Rfq\Enums\BidStatus;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * Supplier quotation header submitted in response to a RequestForQuotation.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $rfq_id
 * @property string|null $invitation_id
 * @property string $supplier_id
 * @property string|null $quote_reference
 * @property Carbon|null $quote_date
 * @property Carbon|null $valid_until
 * @property string $currency
 * @property string $exchange_rate_to_rfq
 * @property int $payment_terms_days
 * @property string|null $incoterms
 * @property string $shipping_cost
 * @property int|null $lead_time_days
 * @property string $status
 * @property string $total_amount
 * @property string $total_net_amount
 * @property string $total_landed_cost
 * @property string|null $weighted_overall_score
 * @property string|null $notes
 * @property string|null $submitted_ip_address
 * @property string|null $submitted_user_agent
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $quote_attachment_file_name
 * @property string|null $quote_attachment_mime_type
 * @property int|null $quote_attachment_size_bytes
 * @property string|null $quote_attachment_disk
 * @property string|null $quote_attachment_path
 * @property string|null $quote_attachment_sha256
 * @property-read RequestForQuotation|null $rfq
 * @property-read RfqSupplierInvitation|null $invitation
 * @property-read Supplier|null $supplier
 * @property-read Collection<int, SupplierBidLine> $lines
 */
final class SupplierBid extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use HasImmutableAuditTrail;

    protected $table = 'procurement_supplier_quotes';

    protected function casts(): array
    {
        return [
            'quote_date' => 'date',
            'valid_until' => 'date',
            'exchange_rate_to_rfq' => 'decimal:6',
            'payment_terms_days' => 'integer',
            'shipping_cost' => 'decimal:4',
            'lead_time_days' => 'integer',
            'total_amount' => 'decimal:2',
            'total_net_amount' => 'decimal:4',
            'total_landed_cost' => 'decimal:4',
            'weighted_overall_score' => 'decimal:2',
            'submitted_at' => 'immutable_datetime',
            'quote_attachment_size_bytes' => 'integer',
        ];
    }

    public function statusEnum(): BidStatus
    {
        return BidStatus::tryFrom((string) $this->status) ?? BidStatus::Submitted;
    }

    /**
     * @return BelongsTo<RequestForQuotation, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(RequestForQuotation::class, 'rfq_id');
    }

    /**
     * @return BelongsTo<RfqSupplierInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(RfqSupplierInvitation::class, 'invitation_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierBidLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBidLine::class, 'supplier_quote_id');
    }
}
