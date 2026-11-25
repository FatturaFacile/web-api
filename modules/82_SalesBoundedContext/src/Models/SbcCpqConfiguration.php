<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $dossier_id
 * @property string|null $quote_id
 * @property string $configuration_code
 * @property string $product_family
 * @property bool $has_constraint_conflict
 * @property string $total_bom_cost
 * @property string $configured_list_price
 * @property string $net_configured_price
 * @property string $calculated_margin_pct
 * @property string $currency
 * @property string $status
 */
final class SbcCpqConfiguration extends BaseEnterpriseModel
{
    protected $table = 'sbc_cpq_configurations';

    protected function casts(): array
    {
        return [
            'selected_options' => 'array',
            'compatibility_rules_evaluated' => 'array',
            'has_constraint_conflict' => 'boolean',
            'conflict_messages' => 'array',
            'super_bom_items' => 'array',
            'routing_operations' => 'array',
            'total_bom_cost' => 'decimal:2',
            'configured_list_price' => 'decimal:2',
            'net_configured_price' => 'decimal:2',
            'calculated_margin_pct' => 'decimal:2',
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
     * @return BelongsTo<SbcCpqQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(SbcCpqQuote::class, 'quote_id');
    }
}
