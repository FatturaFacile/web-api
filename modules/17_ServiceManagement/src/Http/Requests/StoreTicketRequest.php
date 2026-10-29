<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'uuid'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'channel' => ['nullable', 'string', 'in:portal,email,phone,chat,api,field'],
            'service_category_id' => ['nullable', 'uuid'],
            'service_contract_id' => ['nullable', 'uuid'],
            'sla_policy_id' => ['nullable', 'uuid'],
            'assigned_to_id' => ['nullable', 'uuid'],
            'service_team_id' => ['nullable', 'uuid'],
        ];
    }
}
