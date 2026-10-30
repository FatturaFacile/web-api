<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTermTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'payment_term_translations';

    protected $fillable = [
        'tenant_id',
        'payment_term_id',
        'locale',
        'name',
        'invoice_clause',
    ];

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }
}
