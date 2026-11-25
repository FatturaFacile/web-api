<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rate card of a hotel, agency or corporate account (fixed routes, e.g. MXP to Lugano) with the commission the concierge earns.
 *
 * @property string $contact_id
 * @property string $code
 * @property string $name
 * @property string $commission_rate
 * @property string $currency
 * @property list<array{route_code: string, origin_label: string, destination_label: string, vehicle_category: string, price: string, origin_country?: string|null, origin_lat?: float|null, origin_lng?: float|null, destination_country?: string|null, destination_lat?: float|null, destination_lng?: float|null, border_crossing?: string|null, total_km?: float|string|null, it_km?: float|string|null, ch_km?: float|string|null}>|null $rate_card
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $billing_cycle
 * @property bool $is_active
 */
final class HotelConciergeContract extends BaseEnterpriseModel
{
    protected $table = 'ptr_hotel_concierge_contracts';

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:3',
            'rate_card' => 'array',
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
