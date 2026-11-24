<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CPQ quote configuration: the priced quantity of an opportunity (optionally from a saved product configuration of the configurator) with pricing-rule and manual discount, and the approval its total discount may require. draft -> pending_approval -> approved | rejected.
 */
final class QuoteConfiguration extends BaseEnterpriseModel
{
    public const array Statuses = ['draft', 'pending_approval', 'approved', 'rejected'];

    protected $table = 'crm_quote_configurations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'rule_discount_percent' => 'decimal:2',
            'extra_discount_percent' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
