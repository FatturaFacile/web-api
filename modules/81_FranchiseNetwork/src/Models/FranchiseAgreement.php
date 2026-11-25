<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Domain\Enums\GuaranteeType;
use Modules\FranchiseNetwork\Domain\Enums\Jurisdiction;
use Modules\FranchiseNetwork\Domain\Enums\PaymentMethod;

/**
 * Affiliation agreement of a store: term, exclusivity zone, entry fee, minimum purchases, guarantee and collection mandate.
 *
 * @property string $store_id
 * @property string|null $formula_id
 * @property string $agreement_number
 * @property AgreementStatus $status
 * @property Jurisdiction $jurisdiction
 * @property Carbon|null $disclosure_delivered_on
 * @property Carbon|null $signed_on
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $term_months
 * @property bool $auto_renew
 * @property int $renewal_notice_days
 * @property Carbon|null $renewal_reminder_sent_at
 * @property array<string, mixed>|null $exclusivity_zone
 * @property string $entry_fee
 * @property int $entry_fee_deferral_months
 * @property string $min_purchase_amount
 * @property string $min_purchase_source
 * @property list<string>|null $approved_supplier_ids
 * @property GuaranteeType $guarantee_type
 * @property string $guarantee_amount
 * @property string|null $guarantee_reference
 * @property Carbon|null $guarantee_expires_on
 * @property PaymentMethod $payment_method
 * @property string|null $mandate_reference
 * @property Carbon|null $mandate_signed_on
 * @property string $currency
 * @property bool $non_binding_pricing
 * @property string|null $notes
 */
final class FranchiseAgreement extends BaseEnterpriseModel
{
    protected $table = 'frn_agreements';

    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'jurisdiction' => Jurisdiction::class,
            'disclosure_delivered_on' => 'date',
            'signed_on' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'term_months' => 'integer',
            'auto_renew' => 'boolean',
            'renewal_notice_days' => 'integer',
            'renewal_reminder_sent_at' => 'datetime',
            'exclusivity_zone' => 'array',
            'entry_fee' => 'decimal:2',
            'entry_fee_deferral_months' => 'integer',
            'min_purchase_amount' => 'decimal:2',
            'approved_supplier_ids' => 'array',
            'guarantee_type' => GuaranteeType::class,
            'guarantee_amount' => 'decimal:2',
            'guarantee_expires_on' => 'date',
            'payment_method' => PaymentMethod::class,
            'mandate_signed_on' => 'date',
            'non_binding_pricing' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FranchiseStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(FranchiseStore::class, 'store_id');
    }

    /**
     * @return BelongsTo<FranchiseFormula, $this>
     */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(FranchiseFormula::class, 'formula_id');
    }

    /**
     * @return HasMany<EntryFeeDeferral, $this>
     */
    public function deferrals(): HasMany
    {
        return $this->hasMany(EntryFeeDeferral::class, 'agreement_id');
    }
}
