<?php

declare(strict_types=1);

namespace App\Http\Requests\Retail;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosTerminalRequest extends FormRequest
{
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
            'terminal_code' => ['required', 'string', 'max:50', Rule::unique('pos_terminals', 'terminal_code')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'name' => ['required', 'string', 'max:255'],
            'hardware_serial' => ['nullable', 'string', 'max:100'],
            'rt_serial_number' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
