<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;

/**
 * Engine choice of the tenant. Endpoints must be http(s) URLs; the Azure endpoint must be an Azure host, so a tenant cannot
 * point the server at an arbitrary address with a cloud API key attached.
 */
final class UpdateOcrConfigurationRequest extends FormRequest
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
        $providers = Rule::enum(OcrProviderType::class);

        return [
            'primary_provider' => ['required', $providers],
            'fallback_provider' => ['nullable', $providers, 'different:primary_provider'],
            'provider_settings' => ['nullable', 'array'],
            'provider_settings.*' => ['array'],
            'provider_settings.*.endpoint' => ['nullable', 'url:http,https', 'max:255'],
            'provider_settings.*.model' => ['nullable', 'string', 'max:100'],
            'provider_settings.*.api_key' => ['nullable', 'string', 'max:500'],
            'provider_settings.*.languages' => ['nullable', 'string', 'max:50', 'regex:/^[a-z+_]+$/'],
            'auto_confirm_confidence_threshold' => ['nullable', 'numeric', 'min:50', 'max:100'],
            'auto_confirm_enabled' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = (array) $this->input('provider_settings', []);

            foreach ($settings as $provider => $values) {
                if (OcrProviderType::tryFrom((string) $provider) === null) {
                    $validator->errors()->add("provider_settings.{$provider}", 'Unknown OCR provider.');
                }
            }

            $azure = (string) data_get($settings, OcrProviderType::AzureDocumentIntelligence->value.'.endpoint', '');

            if ($azure !== '') {
                $parts = parse_url($azure);
                $host = strtolower((string) ($parts['host'] ?? ''));

                if (($parts['scheme'] ?? '') !== 'https' || ! (str_ends_with($host, '.cognitiveservices.azure.com') || str_ends_with($host, '.api.cognitive.microsoft.com'))) {
                    $validator->errors()->add('provider_settings.'.OcrProviderType::AzureDocumentIntelligence->value.'.endpoint', 'The Azure endpoint must be an https Azure Cognitive Services address.');
                }
            }
        });
    }
}
