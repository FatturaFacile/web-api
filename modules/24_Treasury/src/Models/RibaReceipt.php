<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RibaReceipt extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'riba_presentation_id', 'payment_batch_id', 'payment_line_id', 'invoice_id', 'payment_id', 'receipt_number',
        'due_date', 'amount', 'debtor_name', 'debtor_tax_code', 'debtor_address', 'debtor_postal_code', 'debtor_city',
        'debtor_abi', 'debtor_cab', 'status', 'unpaid_at', 'unpaid_reason', 'unpaid_fee',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'unpaid_at' => 'date', 'unpaid_fee' => 'decimal:2', 'receipt_number' => 'integer'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
