<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Customs\Services\Documents\CustomsDocumentService;

final class CustomsDocumentRequest extends FormRequest
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
        return ['language' => ['sometimes', Rule::in(array_keys(CustomsDocumentService::LanguagePairs))]];
    }
}
