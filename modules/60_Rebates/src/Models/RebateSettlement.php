<?php

declare(strict_types=1);

namespace Modules\Rebates\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The settlement of the accrued rebate: a credit note draft to the customer (sales) or a claim to the supplier
 * (purchases).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $agreement_id
 * @property string $amount
 * @property string $currency
 * @property string $settlement_type
 * @property string|null $credit_note_id
 * @property Carbon $settled_on
 * @property string|null $notes
 * @property string $status
 * @property string|null $execution_mode
 * @property string|null $gross_amount
 * @property string|null $tax_rate
 * @property string|null $tax_amount
 * @property string|null $passive_invoice_id
 * @property string|null $set_off_amount
 * @property string|null $journal_entry_id
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property int $version
 */
final class RebateSettlement extends BaseEnterpriseModel
{
    public const string CreditNoteDraft = 'credit_note_draft';

    public const string SupplierClaim = 'supplier_claim';

    public const string Draft = 'draft';

    public const string PendingApproval = 'pending_approval';

    public const string Approved = 'approved';

    public const string Settled = 'settled';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::PendingApproval, self::Approved, self::Settled, self::Cancelled];

    /** Credit note requested from the supplier (Richiesta nota di credito). */
    public const string CreditNoteRequest = 'credit_note_request';

    /** Active charge invoice issued to the supplier (Fattura attiva di addebito). */
    public const string ChargeInvoice = 'charge_invoice';

    /** Open-item set-off against a payable of the supplier (Compensazione scadenzario passivo). */
    public const string ApSetOff = 'ap_set_off';

    /** @var list<string> */
    public const array ExecutionModes = [self::CreditNoteRequest, self::ChargeInvoice, self::ApSetOff];

    protected $table = 'rebate_settlements';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'set_off_amount' => 'decimal:2', 'settled_on' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }

    /**
     * @return BelongsTo<RebateAgreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'agreement_id');
    }

    /**
     * @return HasMany<RebateAccrual, $this>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(RebateAccrual::class, 'settlement_id');
    }

    /**
     * @return HasMany<RebateSettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RebateSettlementLine::class, 'settlement_id')->orderBy('line_type')->orderBy('reference_date');
    }
}
