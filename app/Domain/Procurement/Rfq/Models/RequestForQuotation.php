<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\DocumentLinkTargetType;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisitionDocumentLink;
use App\Domain\Procurement\Rfq\Enums\RfqStatus;
use App\Domain\Procurement\Rfq\ValueObjects\EvaluationWeights;
use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;

/**
 * Aggregate root for Module 045 (Request for Quotation & Supplier Tendering).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $rfq_number
 * @property string $title
 * @property string|null $description
 * @property string|null $purchase_requisition_id
 * @property string $status
 * @property Carbon|null $issue_date
 * @property Carbon|null $response_deadline
 * @property CarbonImmutable|null $submission_deadline
 * @property Carbon|null $expected_delivery_date
 * @property string $currency
 * @property string|null $incoterms
 * @property array{price_weight?: int, lead_time_weight?: int, supplier_rating_weight?: int}|null $evaluation_weights
 * @property bool $sealed_bidding
 * @property string|null $buyer_user_id
 * @property string|null $cost_center_id
 * @property string|null $award_mode
 * @property string|null $awarded_supplier_id
 * @property string|null $awarded_quote_id
 * @property string|null $purchase_order_id
 * @property CarbonImmutable|null $awarded_at
 * @property string|null $awarded_by_user_id
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RfqLine> $lines
 * @property-read Collection<int, RfqAttachment> $attachments
 * @property-read Collection<int, RfqSupplierInvitation> $invitations
 * @property-read Collection<int, SupplierBid> $bids
 * @property-read User|null $buyer
 * @property-read User|null $awardedBy
 */
final class RequestForQuotation extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use HasImmutableAuditTrail;

    protected $table = 'procurement_rfqs';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'response_deadline' => 'date',
            'submission_deadline' => 'immutable_datetime',
            'expected_delivery_date' => 'date',
            'evaluation_weights' => 'array',
            'sealed_bidding' => 'boolean',
            'awarded_at' => 'immutable_datetime',
        ];
    }

    public function statusEnum(): RfqStatus
    {
        return RfqStatus::tryFrom((string) $this->status) ?? RfqStatus::Draft;
    }

    public function weights(): EvaluationWeights
    {
        return EvaluationWeights::fromArray($this->evaluation_weights);
    }

    public function effectiveDeadline(): CarbonImmutable
    {
        if ($this->submission_deadline !== null) {
            return $this->submission_deadline->utc();
        }

        if ($this->response_deadline !== null) {
            return CarbonImmutable::parse($this->response_deadline->toDateString().' 23:59:59', 'UTC');
        }

        return CarbonImmutable::now('UTC');
    }

    public function isSealedNow(): bool
    {
        if (! $this->sealed_bidding) {
            return false;
        }

        $status = $this->statusEnum();
        if (in_array($status, [RfqStatus::Draft, RfqStatus::Awarded, RfqStatus::Cancelled], true)) {
            return false;
        }

        return CarbonImmutable::now('UTC')->lt($this->effectiveDeadline());
    }

    public function effectiveCurrencyCode(): string
    {
        return strtoupper((string) ($this->currency !== '' ? $this->currency : 'EUR'));
    }

    /**
     * @return HasMany<RfqLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class, 'rfq_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<RfqAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RfqAttachment::class, 'rfq_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<RfqSupplierInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(RfqSupplierInvitation::class, 'rfq_id');
    }

    /**
     * @return HasMany<SupplierBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(SupplierBid::class, 'rfq_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function awardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by_user_id');
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function awardedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'awarded_supplier_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseRequisitionDocumentLink, $this>
     */
    public function sourceRequisitionLinks(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionDocumentLink::class, 'target_document_id')
            ->where('target_document_type', DocumentLinkTargetType::RfqMod045->value);
    }
}
