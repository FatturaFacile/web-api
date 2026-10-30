<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethodTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'payment_method_translations';

    protected $fillable = [
        'tenant_id',
        'payment_method_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
