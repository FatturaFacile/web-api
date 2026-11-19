<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class IntercompanyTransaction extends BaseEnterpriseModel
{
    protected $table = 'intercompany_transactions';

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'net_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'seller_company_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    /**
     * @return HasMany<IntercompanyDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(IntercompanyDocument::class, 'transaction_id');
    }
}
