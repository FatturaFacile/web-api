<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $company_id
 * @property string|null $employee_id
 * @property string $holder_name
 * @property string $card_last4
 * @property string|null $issuer
 * @property string $currency
 * @property bool $is_active
 */
final class CorporateCardAccount extends BaseEnterpriseModel
{
    protected $table = 'exm_card_accounts';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<CorporateCardTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CorporateCardTransaction::class, 'card_account_id');
    }
}
