<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\AiCognitive\Application\Commands\ConfigureAiProviderCommand;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;
use Modules\AiCognitive\Infrastructure\Services\AiAuditor;
use Modules\AiCognitive\Infrastructure\Services\AiTenantRunner;

/**
 * Saves the provider setup of a tenant. The API key is encrypted by the model cast; the audit trail records that a key was
 * set, never the key.
 */
final readonly class ConfigureAiProviderHandler
{
    public function __construct(private AiTenantRunner $runner, private AiAuditor $auditor) {}

    public function handle(ConfigureAiProviderCommand $command): AiProviderConfig
    {
        return $this->runner->run($command->tenantId, fn (): AiProviderConfig => DB::transaction(function () use ($command): AiProviderConfig {
            $config = AiProviderConfig::query()->where('tenant_id', $command->tenantId)->where('provider', $command->provider->value)->first()
                ?? new AiProviderConfig(['tenant_id' => $command->tenantId, 'provider' => $command->provider]);

            $stored = $config->credentials_encrypted ?? [];
            $credentials = $command->extra !== [] ? $command->extra : array_diff_key($stored, ['api_key' => true]);
            $key = $command->apiKey ?? ($stored['api_key'] ?? null);

            if ($key !== null && $key !== '') {
                $credentials['api_key'] = $key;
            }

            $config->fill([
                'label' => $command->label,
                'is_enabled' => $command->enabled,
                'is_default' => $command->default,
                'endpoint' => $command->endpoint,
                'model' => $command->model,
                'light_model' => $command->lightModel,
                'embedding_model' => $command->embeddingModel,
                'credentials_encrypted' => $credentials === [] ? null : $credentials,
                'max_data_classification' => $command->maxDataClassification,
                'pii_masking_enabled' => $command->piiMasking,
                'latency_sla_ms' => $command->latencySlaMs,
                'monthly_token_budget' => $command->monthlyTokenBudget,
                'rate_limit_per_minute' => $command->rateLimitPerMinute,
            ])->save();

            if ($command->default) {
                AiProviderConfig::query()->where('tenant_id', $command->tenantId)->where('id', '!=', $config->id)->update(['is_default' => false]);
            }

            $this->auditor->record($command->tenantId, 'provider_configured', $command->userId, 'ai_provider_config', $config->id, $command->provider->value, $config->model, null, [
                'enabled' => $command->enabled,
                'default' => $command->default,
                'pii_masking' => $command->piiMasking,
                'max_data_classification' => $command->maxDataClassification->value,
                'has_api_key' => $config->apiKey() !== null,
                'monthly_token_budget' => $command->monthlyTokenBudget,
            ]);

            return $config->refresh();
        }));
    }
}
