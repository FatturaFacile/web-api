<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class SubmitCSATRequest extends FormRequest
{
    use TenantScopedRules;

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
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string'],
            'ticket_id' => ['nullable', 'uuid', $this->tenantOwns('esm_tickets')],
            'work_order_id' => ['nullable', 'uuid', $this->tenantOwns('esm_work_orders')],
            'customer_id' => ['nullable', 'uuid', $this->tenantOwns('contacts')],
            'respondent_email' => ['nullable', 'email'],
        ];
    }
}
