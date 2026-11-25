<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\ElectronicSignature\Enums\SignableType;

/**
 * Which signature tier does a document of this type and amount need.
 */
final class PolicyQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('signatures.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(SignableType::class)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
