<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;

/**
 * The provider choice of a tenant. The credentials are encrypted at rest, hidden from every serialization and never enter
 * the audit ledger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property AiProviderType $provider
 * @property string|null $label
 * @property bool $is_enabled
 * @property bool $is_default
 * @property string|null $endpoint
 * @property string|null $model
 * @property string|null $light_model
 * @property string|null $embedding_model
 * @property array<string, mixed>|null $credentials_encrypted
 * @property DataClassification $max_data_classification
 * @property bool $pii_masking_enabled
 * @property int|null $latency_sla_ms
 * @property int|null $monthly_token_budget
 * @property int|null $rate_limit_per_minute
 */
final class AiProviderConfig extends BaseEnterpriseModel
{
    protected $table = 'ai_provider_configs';

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials_encrypted'];

    protected function casts(): array
    {
        return [
            'provider' => AiProviderType::class,
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'credentials_encrypted' => 'encrypted:array',
            'max_data_classification' => DataClassification::class,
            'pii_masking_enabled' => 'boolean',
            'latency_sla_ms' => 'integer',
            'monthly_token_budget' => 'integer',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function apiKey(): ?string
    {
        $key = ($this->credentials_encrypted ?? [])['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Provider specific credentials and options (Azure: deployment, api_version) without the key.
     *
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        return array_diff_key($this->credentials_encrypted ?? [], ['api_key' => true]);
    }

    public function auditPayload(array $attributes): array
    {
        unset($attributes['credentials_encrypted']);

        return $attributes;
    }
}
