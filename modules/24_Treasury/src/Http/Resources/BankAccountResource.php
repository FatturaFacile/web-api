<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\BankAccount;

/**
 * @mixin BankAccount
 */
final class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'bank_name' => $this->bank_name,
            'branch_name' => $this->branch_name,
            'iban' => $this->iban,
            'bic_swift' => $this->bic_swift,
            'currency' => $this->currency,
            'account_type' => $this->account_type,
            'current_balance' => (float) $this->current_balance,
            'available_balance' => (float) $this->available_balance,
            'overdraft_limit' => (float) $this->overdraft_limit,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'is_pool_header' => (bool) $this->is_pool_header,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
