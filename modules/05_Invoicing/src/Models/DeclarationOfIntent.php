<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * A declaration of intent (lettera di intento): `received` from a customer, or `issued` to a supplier.
 */
final class DeclarationOfIntent extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const string RECEIVED = 'received';

    public const string ISSUED = 'issued';

    protected $table = 'declarations_of_intent';

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'direction',
        'protocol_number',
        'receipt_date',
        'year',
        'plafond_amount',
        'consumed_amount',
        'valid_from',
        'valid_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'year' => 'integer',
            'plafond_amount' => 'decimal:2',
            'consumed_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function residualAmount(): string
    {
        return Decimal::round2(Decimal::sub((string) $this->plafond_amount, (string) $this->consumed_amount));
    }
}
