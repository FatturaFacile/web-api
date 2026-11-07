<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Documents\Services\DocumentLinkRegistry;

final class LinkDocumentRequest extends FormRequest
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
            'linkable_type' => ['required', 'string', Rule::in(array_keys(app(DocumentLinkRegistry::class)->targets()))],
            'linkable_id' => ['required', 'uuid'],
        ];
    }
}
