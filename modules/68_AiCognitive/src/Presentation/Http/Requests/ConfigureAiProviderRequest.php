<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * Provider setup of a tenant. A hosted provider may only point at its own service: the endpoint of OpenAI, Anthropic and
 * Gemini must be the official host and an Azure endpoint an Azure OpenAI resource, so a tenant cannot send its API key (or
 * the data) to an arbitrary address. Only the local Ollama endpoint is free.
 */
final class ConfigureAiProviderRequest extends FormRequest
{
    private const array OfficialHosts = [
        'openai' => ['api.openai.com'],
        'anthropic' => ['api.anthropic.com'],
        'gemini' => ['generativelanguage.googleapis.com'],
    ];

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
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:120'],
            'endpoint' => ['nullable', 'url:http,https', 'max:255'],
            'model' => ['nullable', 'string', 'max:100'],
            'light_model' => ['nullable', 'string', 'max:100'],
            'embedding_model' => ['nullable', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'extra' => ['nullable', 'array'],
            'extra.deployment' => ['nullable', 'string', 'max:100'],
            'extra.embedding_deployment' => ['nullable', 'string', 'max:100'],
            'extra.api_version' => ['nullable', 'string', 'max:30', 'regex:/^[0-9a-z.-]+$/i'],
            'max_data_classification' => ['required', Rule::enum(DataClassification::class)],
            'pii_masking_enabled' => ['required', 'boolean'],
            'latency_sla_ms' => ['nullable', 'integer', 'min:100', 'max:120000'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:0'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function provider(): AiProviderType
    {
        return AiProviderType::from((string) $this->route('provider'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = AiProviderType::tryFrom((string) $this->route('provider'));

            if ($provider === null) {
                $validator->errors()->add('provider', 'Unknown AI provider.');

                return;
            }

            $endpoint = (string) $this->input('endpoint', '');

            if ($endpoint === '' || $provider->isLocal()) {
                return;
            }

            $parts = parse_url($endpoint);
            $host = strtolower((string) ($parts['host'] ?? ''));

            if (($parts['scheme'] ?? '') !== 'https') {
                $validator->errors()->add('endpoint', 'A hosted provider is reached over https.');

                return;
            }

            $allowed = $provider === AiProviderType::AzureOpenAi
                ? str_ends_with($host, '.openai.azure.com') || str_ends_with($host, '.cognitiveservices.azure.com')
                : in_array($host, self::OfficialHosts[$provider->value] ?? [], true);

            if (! $allowed) {
                $validator->errors()->add('endpoint', 'The address must be the official service address of the provider.');
            }
        });
    }
}
