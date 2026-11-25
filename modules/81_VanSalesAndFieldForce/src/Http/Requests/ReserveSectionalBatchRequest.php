<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;

final class ReserveSectionalBatchRequest extends FormRequest
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
            'document_type' => ['required', Rule::in(VanFiscalDocumentType::values())],
            'count' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
