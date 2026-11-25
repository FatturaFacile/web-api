<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Municipal school bus contract: the fare of each route and the optional monthly flat amount, billed once a month.
 *
 * @property string $contact_id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property list<array{route_code: string, label: string, price: string}>|null $rate_card
 * @property string|null $monthly_flat_amount
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 */
final class SchoolBusContract extends BaseEnterpriseModel
{
    protected $table = 'ptr_school_bus_contracts';

    protected function casts(): array
    {
        return [
            'rate_card' => 'array',
            'monthly_flat_amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
