<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IndustrialLaundry\Domain\Enums\PpeAction;

final class ResolvePpeAlertRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in([...PpeAction::values(), 'complete_mending'])],
            'replacement_epc' => ['nullable', 'required_if:action,replace', 'string', 'max:80'],
            'reason' => ['nullable', 'string', 'max:60'],
        ];
    }
}
