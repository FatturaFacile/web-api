<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;

/**
 * OCR engine choice of a tenant. The engine settings (endpoint, model, API keys) are stored encrypted and never returned.
 *
 * @property string $id
 * @property string $tenant_id
 * @property OcrProviderType $primary_provider
 * @property OcrProviderType|null $fallback_provider
 * @property array<string, mixed>|null $provider_settings_encrypted
 * @property string $auto_confirm_confidence_threshold
 * @property bool $auto_confirm_enabled
 * @property string|null $dedicated_inbox_alias
 */
final class TenantOcrConfiguration extends BaseEnterpriseModel
{
    protected $table = 'tenant_ocr_configurations';

    /**
     * @var list<string>
     */
    protected $hidden = ['provider_settings_encrypted'];

    protected function casts(): array
    {
        return [
            'primary_provider' => OcrProviderType::class,
            'fallback_provider' => OcrProviderType::class,
            'provider_settings_encrypted' => 'encrypted:array',
            'auto_confirm_confidence_threshold' => 'decimal:2',
            'auto_confirm_enabled' => 'boolean',
        ];
    }

    /**
     * Settings of one engine (endpoint, model, api_key, ...) from the encrypted bag keyed by provider.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(OcrProviderType $provider): array
    {
        $bag = $this->provider_settings_encrypted ?? [];
        $settings = $bag[$provider->value] ?? [];

        return is_array($settings) ? $settings : [];
    }
}
