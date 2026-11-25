<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesBoundedContext\Domain\Enums\TenderProcurementFramework;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string|null $converted_order_id
 * @property string $tender_code
 * @property string $title
 * @property TenderProcurementFramework|null $procurement_framework
 * @property string $contracting_authority
 * @property string|null $cig_code
 * @property string|null $cup_code
 * @property string|null $soa_category
 * @property string $edgue_status
 * @property string|null $fvoe_passoe_ref
 * @property array<string, mixed>|null $qualification_vault
 * @property string $admin_envelope_status
 * @property string $technical_envelope_score
 * @property string $economic_envelope_score
 * @property string $total_award_score
 * @property string $bid_amount
 * @property string $currency
 * @property string $pipeline_stage
 */
final class SbcPublicPrivateTender extends BaseEnterpriseModel
{
    protected $table = 'sbc_public_private_tenders';

    protected function casts(): array
    {
        return [
            'procurement_framework' => TenderProcurementFramework::class,
            'qualification_vault' => 'array',
            'technical_envelope_score' => 'decimal:2',
            'economic_envelope_score' => 'decimal:2',
            'total_award_score' => 'decimal:2',
            'bid_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SbcCustomerDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(SbcCustomerDossier::class, 'dossier_id');
    }

    /**
     * @return BelongsTo<SbcFrameworkOrder, $this>
     */
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(SbcFrameworkOrder::class, 'converted_order_id');
    }
}
