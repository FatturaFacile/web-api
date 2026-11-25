<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\ContractStatus;
use Modules\GdoTradeTerms\Domain\Enums\Jurisdiction;
use Modules\GdoTradeTerms\Support\Money;

/**
 * A framework contract with a retail chain, buying group or wholesaler: on-invoice discounts, the tiered year-end rebate
 * scales and the trade promotions of one commercial year (or part of it).
 *
 * @property string $code
 * @property string $name
 * @property string $contact_id
 * @property bool $include_child_contacts
 * @property Jurisdiction $jurisdiction
 * @property string $currency
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property ContractStatus $status
 * @property string $discount_1_percent
 * @property string $discount_2_percent
 * @property string $discount_3_percent
 * @property string $cedi_discount_percent
 * @property string $pallet_discount_amount
 * @property string $tolerance_percent
 * @property string|null $expense_account_id
 * @property string|null $provision_account_id
 * @property string|null $payable_account_id
 * @property string|null $vat_account_id
 * @property string|null $notes
 * @property Carbon|null $activated_at
 * @property Carbon|null $closed_at
 */
final class GdoContract extends BaseEnterpriseModel
{
    protected $table = 'gdt_contracts';

    protected function casts(): array
    {
        return [
            'include_child_contacts' => 'boolean',
            'jurisdiction' => Jurisdiction::class,
            'status' => ContractStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'discount_1_percent' => 'decimal:4',
            'discount_2_percent' => 'decimal:4',
            'discount_3_percent' => 'decimal:4',
            'cedi_discount_percent' => 'decimal:4',
            'pallet_discount_amount' => 'decimal:4',
            'tolerance_percent' => 'decimal:4',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasMany<GdoScale, $this>
     */
    public function scales(): HasMany
    {
        return $this->hasMany(GdoScale::class, 'contract_id')->orderBy('code');
    }

    /**
     * @return HasMany<GdoPromotion, $this>
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(GdoPromotion::class, 'contract_id')->orderBy('starts_on');
    }

    /**
     * The combined on-invoice discount of the cascade (1st, 2nd, 3rd discount and Ce.Di.): 100 - 100 x prod(1 - d/100).
     */
    public function effectiveOnInvoiceDiscountPercent(): string
    {
        return Money::cascadeDiscount([$this->discount_1_percent, $this->discount_2_percent, $this->discount_3_percent, $this->cedi_discount_percent]);
    }

    public function isLive(): bool
    {
        return in_array($this->status, [ContractStatus::Active, ContractStatus::Closed], true);
    }
}
