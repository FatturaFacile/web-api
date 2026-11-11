<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\GeneralLedger\Enums\IntercompanyMappingType;

final class StoreIntercompanyMappingRequest extends FormRequest
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
            'mapping_type' => ['required', Rule::enum(IntercompanyMappingType::class)],
            'account_a_id' => ['required', 'uuid'],
            'account_b_id' => ['nullable', 'uuid'],
        ];
    }
}
