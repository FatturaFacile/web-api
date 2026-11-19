<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blanket agreement (call-off contract): a customer commits to a quantity or an amount over a validity period and
 * releases it in call-off sales orders priced from the contract.
 */
/**
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property Carbon|null $activated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BlanketAgreement extends BaseEnterpriseModel
{
    protected $table = 'blanket_agreements';

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'exchange_rate' => 'decimal:6',
            'total_committed_amount' => 'decimal:4',
            'total_called_off_amount' => 'decimal:4',
            'over_release_tolerance_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasMany<BlanketAgreementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BlanketAgreementLine::class, 'blanket_agreement_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<SalesOrder, $this>
     */
    public function callOffOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'blanket_agreement_id');
    }
}
