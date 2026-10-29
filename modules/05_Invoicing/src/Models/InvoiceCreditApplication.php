<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceCreditApplication extends BaseEnterpriseModel
{
    protected $table = 'invoice_credit_applications';

    protected function casts(): array
    {
        return [
            'applied_amount' => 'decimal:2',
            'application_date' => 'date',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'credit_note_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'original_invoice_id');
    }
}
