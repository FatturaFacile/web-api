<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The expense of the expenses feature that settles the trip.
 */
final class LinkExpenseRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policy.
     */
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
            'expense_id' => ['required', 'uuid', $this->tenantOwns('expenses')],
        ];
    }
}
