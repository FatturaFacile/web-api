<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FranchiseNetwork\Domain\Enums\LoyaltyType;
use Modules\FranchiseNetwork\Http\Requests\Concerns\ScopesToTenant;

final class LoyaltyTransactionRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'uuid', $this->tenantExists('frn_stores')],
            'earned_store_id' => ['nullable', 'uuid', $this->tenantExists('frn_stores')],
            'customer_ref' => ['required', 'string', 'max:64'],
            'type' => ['required', Rule::in(LoyaltyType::values())],
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
