<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLink extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'invoice_id', 'provider', 'provider_reference', 'url', 'amount', 'currency', 'status', 'payment_id', 'paid_at', 'review_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
