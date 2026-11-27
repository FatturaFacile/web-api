<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\ContractStatus;
use Modules\GreenCare\Domain\Enums\InstallmentFrequency;

/**
 * A seasonal maintenance contract of a site (lawn cuts, hedges, fertilisation, irrigation, winter snow clearing).
 *
 * @property string $contract_number
 * @property string $site_id
 * @property string $customer_id
 * @property string|null $condominium_administrator_id
 * @property Carbon $season_start_date
 * @property Carbon $season_end_date
 * @property string $jurisdiction
 * @property BillingMode $billing_mode
 * @property int $included_passages_count
 * @property int $completed_passages_count
 * @property string $extra_passage_unit_price
 * @property string $fixed_fee_amount
 * @property InstallmentFrequency $installment_frequency
 * @property int $installments_billed
 * @property string $time_and_material_hourly_rate
 * @property string|null $snow_removal_standby_fee
 * @property string|null $snow_trigger_threshold_cm
 * @property bool $snow_standby_billed
 * @property string $vat_rate
 * @property list<string>|null $included_services
 * @property bool $auto_bill_extra_passages
 * @property bool $apply_condominium_withholding
 * @property bool $is_condominium
 * @property string|null $condominium_fiscal_code
 * @property ContractStatus $status
 */
final class SeasonalMaintenanceContract extends BaseEnterpriseModel
{
    protected $table = 'seasonal_maintenance_contracts';

    protected function casts(): array
    {
        return [
            'season_start_date' => 'date',
            'season_end_date' => 'date',
            'billing_mode' => BillingMode::class,
            'included_passages_count' => 'integer',
            'completed_passages_count' => 'integer',
            'extra_passage_unit_price' => 'decimal:2',
            'fixed_fee_amount' => 'decimal:2',
            'installment_frequency' => InstallmentFrequency::class,
            'installments_billed' => 'integer',
            'time_and_material_hourly_rate' => 'decimal:2',
            'snow_removal_standby_fee' => 'decimal:2',
            'snow_trigger_threshold_cm' => 'decimal:2',
            'snow_standby_billed' => 'boolean',
            'vat_rate' => 'decimal:2',
            'included_services' => 'array',
            'auto_bill_extra_passages' => 'boolean',
            'apply_condominium_withholding' => 'boolean',
            'is_condominium' => 'boolean',
            'status' => ContractStatus::class,
        ];
    }

    /**
     * @return BelongsTo<GreenSpaceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(GreenSpaceSite::class, 'site_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function condominiumAdministrator(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'condominium_administrator_id');
    }

    /**
     * @return HasMany<CrewRouteStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(CrewRouteStop::class, 'contract_id');
    }

    public function extraPassagesCount(): int
    {
        return max(0, $this->completed_passages_count - $this->included_passages_count);
    }

    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    public function coversDate(Carbon $date): bool
    {
        return $this->season_start_date->lte($date) && $this->season_end_date->gte($date);
    }
}
