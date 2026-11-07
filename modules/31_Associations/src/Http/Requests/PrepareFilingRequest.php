<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Associations\Services\ThirdSectorFilingService;

final class PrepareFilingRequest extends FormRequest
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
            'form' => ['required', Rule::in(ThirdSectorFilingService::Forms)],
            'reference' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
