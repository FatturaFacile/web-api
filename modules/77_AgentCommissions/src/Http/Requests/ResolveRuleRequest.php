<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\AgentCommissions\Models\CommissionRule;

final class ResolveRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', CommissionRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_profile_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'product_category_id' => ['nullable', 'uuid'],
            'date' => ['nullable', 'date'],
        ];
    }
}
