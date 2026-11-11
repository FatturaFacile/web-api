<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The first consolidation of a member: the date, the cost of the investment and the book net assets of the member at
 * that date, all in group currency and entered by the user (the ledger of the member is not rebuilt at a past date).
 * Fair-value adjustments and the deferred tax on them are inputs; goodwill is computed by the consolidation.
 *
 * @property string $id
 * @property string $group_id
 * @property string $member_tenant_id
 * @property Carbon $acquisition_date
 * @property string|null $cost
 * @property string $net_assets_at_acquisition
 * @property string|null $acquired_percentage
 * @property bool $recognise_deferred_tax
 * @property string|null $deferred_tax_rate
 * @property string|null $input_currency
 * @property string|null $expected_future_charges
 * @property int|null $provision_release_years
 */
final class ConsolidationAcquisition extends BaseEnterpriseModel
{
    protected $table = 'gl_consolidation_acquisitions';

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'cost' => 'decimal:4',
            'net_assets_at_acquisition' => 'decimal:4',
            'acquired_percentage' => 'decimal:4',
            'recognise_deferred_tax' => 'boolean',
            'deferred_tax_rate' => 'decimal:4',
            'expected_future_charges' => 'decimal:4',
            'provision_release_years' => 'integer',
        ];
    }

    /**
     * @return HasMany<ConsolidationFairValueAdjustment, $this>
     */
    public function fairValueAdjustments(): HasMany
    {
        return $this->hasMany(ConsolidationFairValueAdjustment::class, 'acquisition_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<ConsolidationGoodwillImpairment, $this>
     */
    public function impairments(): HasMany
    {
        return $this->hasMany(ConsolidationGoodwillImpairment::class, 'acquisition_id')->orderBy('fiscal_year');
    }
}
