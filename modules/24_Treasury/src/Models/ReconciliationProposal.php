<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use App\Models\BankStatementLine;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ranked suggestion of the smart reconciliation engine: one statement line, one open document, the rule that found it
 * and the confidence (0-100). `matched_amount` is what settles the document, `fee_amount` the bank charge and
 * `fx_difference` the exchange difference that would be posted; `residual_amount` is what stays open.
 *
 * @property string $bank_statement_line_id
 * @property string $document_type
 * @property string $document_id
 * @property string $rule
 * @property int $confidence
 * @property string $matched_amount
 * @property string $fee_amount
 * @property string $fx_difference
 * @property string $residual_amount
 * @property string $currency
 * @property string $status
 * @property array<string, mixed>|null $evidence
 * @property CarbonInterface|null $applied_at
 * @property string|null $applied_by
 */
final class ReconciliationProposal extends BaseEnterpriseModel
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    public const DOCUMENT_INVOICE = 'invoice';

    public const DOCUMENT_PASSIVE_INVOICE = 'passive_invoice';

    protected $table = 'reconciliation_proposals';

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'matched_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'fx_difference' => 'decimal:2',
            'residual_amount' => 'decimal:2',
            'evidence' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankStatementLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }
}
