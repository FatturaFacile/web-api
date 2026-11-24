<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One due date (scadenza) of an issued sales document: the amount the customer owes on that date. The installments are
 * fixed at emission from the payment term of the document and always sum to the amount due (the receivable). What is
 * still open on each one is derived from the payments of the document, applied to the earliest installments first.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property int $sequence
 * @property Carbon $due_date
 * @property string $amount
 */
final class InvoiceInstallment extends BaseEnterpriseModel
{
    protected $table = 'invoice_installments';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'invoice_id');
    }
}
