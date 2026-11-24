<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's registration of a deal for a customer: it protects the partner while approved and not expired. pending -> approved | rejected; approved -> won | expired.
 */
final class DealRegistration extends BaseEnterpriseModel
{
    public const array Statuses = ['pending', 'approved', 'rejected', 'expired', 'won'];

    protected $table = 'crm_deal_registrations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'registered_on' => 'date',
            'expires_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
