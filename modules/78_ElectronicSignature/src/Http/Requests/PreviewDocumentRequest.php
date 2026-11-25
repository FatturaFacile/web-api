<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use App\Models\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\ElectronicSignature\Enums\SignableType;

/**
 * Which business document to render for the placement of the signature boxes.
 */
final class PreviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('signatures.send');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signable_type' => ['required', Rule::enum(SignableType::class), Rule::notIn([SignableType::Upload->value])],
            'signable_id' => ['required', 'uuid'],
            'locale' => ['nullable', 'string', Rule::in(array_merge(Translation::SUPPORTED_LOCALES, array_keys(Translation::REGIONAL_LOCALES)))],
        ];
    }
}
