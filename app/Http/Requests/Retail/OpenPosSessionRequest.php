<?php

declare(strict_types=1);

namespace App\Http\Requests\Retail;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenPosSessionRequest extends FormRequest
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
            'pos_terminal_id' => ['required', 'uuid', Rule::exists('pos_terminals', 'id')->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->getTenantId())->orWhereNull('tenant_id'))],
            'opening_float' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
