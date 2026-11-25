<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Requests;

use App\Rules\PngSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The signature of a recipient on the public portal: explicit consents, the signature itself and the identity proof.
 */
final class SignDocumentRequest extends FormRequest
{
    private const string PngHeader = "\x89PNG\r\n\x1a\n";

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
            'consent_terms' => ['required', 'accepted'],
            'consent_legal' => ['required', 'accepted'],
            'signature_method' => ['required', Rule::in(['typed', 'drawn', 'click'])],
            'signature_data' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('signature_method'), ['typed', 'drawn'], true)),
                'nullable',
                'string',
                'max:400000',
            ],
            'otp' => ['nullable', 'string', 'max:12'],
            'identity' => ['nullable', 'array'],
            'identity.id_document_type' => ['nullable', 'string', Rule::in(['passport', 'identity_card', 'driving_licence', 'residence_permit'])],
            'identity.id_document_number' => ['nullable', 'string', 'max:40'],
            'identity.fiscal_code' => ['nullable', 'string', 'max:32'],
            'identity.ahv_number' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator): void {
            if ($this->input('signature_method') !== 'drawn' || $validator->errors()->has('signature_data')) {
                return;
            }

            $binary = PngSignature::decode((string) $this->input('signature_data'));

            if ($binary === null || strlen($binary) > PngSignature::MAX_KILOBYTES * 1024 || ! str_starts_with($binary, self::PngHeader)) {
                $validator->errors()->add('signature_data', 'signature_invalid');
            }
        }];
    }
}
