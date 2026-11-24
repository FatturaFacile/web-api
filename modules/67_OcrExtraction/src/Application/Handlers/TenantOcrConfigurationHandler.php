<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Application\Handlers;

use App\Models\Tenant;
use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Infrastructure\Models\TenantOcrConfiguration;
use Modules\OcrExtraction\Infrastructure\Services\OcrTenantRunner;

/**
 * Reads and changes the OCR engine choice of a tenant. Secrets are write-only: the answer says which settings exist, never
 * their values.
 */
final readonly class TenantOcrConfigurationHandler
{
    public function __construct(private OcrTenantRunner $runner, private RecordAuditAction $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function show(string $tenantId): array
    {
        return $this->runner->run($tenantId, fn (Tenant $tenant): array => $this->present($tenant, TenantOcrConfiguration::query()->where('tenant_id', $tenantId)->first()));
    }

    /**
     * @param  array{primary_provider: string, fallback_provider?: string|null, provider_settings?: array<string, array<string, mixed>>, auto_confirm_confidence_threshold?: float|int|string|null, auto_confirm_enabled?: bool|null}  $data
     * @return array<string, mixed>
     */
    public function update(string $tenantId, array $data): array
    {
        return $this->runner->run($tenantId, function (Tenant $tenant) use ($tenantId, $data): array {
            $configuration = TenantOcrConfiguration::query()->firstOrNew(['tenant_id' => $tenantId]);
            $settings = $configuration->provider_settings_encrypted ?? [];

            foreach ((array) ($data['provider_settings'] ?? []) as $provider => $values) {
                // A blank value keeps the stored secret; a new value replaces it.
                $settings[$provider] = [...($settings[$provider] ?? []), ...array_filter((array) $values, static fn (mixed $value): bool => $value !== null && $value !== '')];
            }

            $configuration->fill([
                'primary_provider' => OcrProviderType::from($data['primary_provider']),
                'fallback_provider' => isset($data['fallback_provider']) ? OcrProviderType::from($data['fallback_provider']) : null,
                'provider_settings_encrypted' => $settings,
                'auto_confirm_confidence_threshold' => $data['auto_confirm_confidence_threshold'] ?? ($configuration->auto_confirm_confidence_threshold ?? 98),
                'auto_confirm_enabled' => (bool) ($data['auto_confirm_enabled'] ?? $configuration->auto_confirm_enabled ?? false),
            ]);

            $configuration->dedicated_inbox_alias ??= $this->alias($tenant);
            $configuration->save();

            $this->audit->execute('OCR_CONFIGURATION_CHANGED', $configuration, null, [
                'primary_provider' => $configuration->primary_provider->value,
                'fallback_provider' => $configuration->fallback_provider?->value,
                'auto_confirm_enabled' => $configuration->auto_confirm_enabled,
            ], 'ocr_extraction');

            return $this->present($tenant, $configuration);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant, ?TenantOcrConfiguration $configuration): array
    {
        $settings = $configuration->provider_settings_encrypted ?? [];

        return [
            'primary_provider' => ($configuration->primary_provider ?? OcrProviderType::OllamaLocal)->value,
            'fallback_provider' => $configuration?->fallback_provider?->value,
            'configured_providers' => array_map(static function (array $values): array {
                $keys = array_keys($values);
                sort($keys);

                return $keys;
            }, $settings),
            'auto_confirm_confidence_threshold' => (float) ($configuration->auto_confirm_confidence_threshold ?? 98),
            'auto_confirm_enabled' => (bool) ($configuration->auto_confirm_enabled ?? false),
            'dedicated_inbox_alias' => $configuration->dedicated_inbox_alias ?? $this->alias($tenant),
            'providers' => array_map(static fn (OcrProviderType $type): array => ['value' => $type->value, 'is_cloud' => $type->isCloud()], OcrProviderType::cases()),
        ];
    }

    private function alias(Tenant $tenant): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($tenant->slug ?? $tenant->name ?? $tenant->id))), '-');

        return 'invoices@'.($slug !== '' ? $slug : substr((string) $tenant->id, 0, 8)).'.'.config('ocr_extraction.inbound_email_domain');
    }
}
