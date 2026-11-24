<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreConsolidationAccountMappingRequest extends FormRequest
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
            'member_tenant_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'group_account_code' => ['required', 'string', 'max:40'],
            'group_account_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
