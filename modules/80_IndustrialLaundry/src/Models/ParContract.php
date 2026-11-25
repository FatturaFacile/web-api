<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\BillingModel;
use Modules\IndustrialLaundry\Domain\Enums\CustomerSegment;
use Modules\IndustrialLaundry\Domain\Enums\Jurisdiction;
use Modules\IndustrialLaundry\Domain\Enums\KgBasis;

/**
 * A customer linen par stock contract (CustomerLinenParStockContract): the fixed endowment in circulation, the prices and the
 * billing model of a hotel, restaurant, clinic or nursing home.
 *
 * @property string $code
 * @property string $customer_id
 * @property CustomerSegment $customer_segment
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property BillingModel $billing_model
 * @property KgBasis $kg_basis
 * @property string $unreturned_garment_replacement_price
 * @property int $unreturned_threshold_days
 * @property Jurisdiction $jurisdiction
 * @property string $currency
 * @property string $tax_rate
 * @property string|null $notes
 */
final class ParContract extends BaseEnterpriseModel
{
    protected $table = 'ilr_par_contracts';

    protected function casts(): array
    {
        return [
            'customer_segment' => CustomerSegment::class,
            'billing_model' => BillingModel::class,
            'kg_basis' => KgBasis::class,
            'jurisdiction' => Jurisdiction::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'unreturned_garment_replacement_price' => 'decimal:4',
            'unreturned_threshold_days' => 'integer',
            'tax_rate' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasMany<ParContractLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ParContractLine::class, 'contract_id');
    }

    public function coversPeriod(Carbon $start, Carbon $end): bool
    {
        return $this->valid_from->lessThanOrEqualTo($end) && ($this->valid_to === null || $this->valid_to->greaterThanOrEqualTo($start));
    }
}
