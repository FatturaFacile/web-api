<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;

/**
 * A provider setup without its secrets: only whether a key is stored.
 *
 * @mixin AiProviderConfig
 */
final class AiProviderConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider->value,
            'label' => $this->label,
            'is_enabled' => $this->is_enabled,
            'is_default' => $this->is_default,
            'is_local' => $this->provider->isLocal(),
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'light_model' => $this->light_model,
            'embedding_model' => $this->embedding_model,
            'has_api_key' => $this->apiKey() !== null,
            'extra' => $this->extra(),
            'max_data_classification' => $this->max_data_classification->value,
            'pii_masking_enabled' => $this->pii_masking_enabled,
            'latency_sla_ms' => $this->latency_sla_ms,
            'monthly_token_budget' => $this->monthly_token_budget,
            'rate_limit_per_minute' => $this->rate_limit_per_minute,
        ];
    }
}
