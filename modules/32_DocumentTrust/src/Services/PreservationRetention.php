<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\DocumentTrust\Models\PreservationSetting;

/**
 * Retention period per document class. The tenant sets the years; until it does, the commonly cited default of the class
 * applies and is reported as unconfirmed. No period here is a verified legal value: the accountant confirms them.
 */
final readonly class PreservationRetention
{
    /**
     * Inject the tenant context.
     */
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Years that apply to a class now, and where they come from (`tenant_setting` or `suggested_default`).
     *
     * @return array{years: int, source: string}
     */
    public function effective(PreservationDocumentClass $class): array
    {
        $configured = $this->settings()?->retention_years[$class->value] ?? null;

        return $configured !== null
            ? ['years' => (int) $configured, 'source' => 'tenant_setting']
            : ['years' => $class->suggestedRetentionYears(), 'source' => 'suggested_default'];
    }

    /**
     * The settings with every class, for the API.
     *
     * @return array{classes: list<array{document_class: string, suggested_years: int, configured_years: ?int, effective_years: int, source: string}>, confirmed_by_accountant: bool, confirmed_at: ?string, notes: ?string, disclaimer: string}
     */
    public function overview(): array
    {
        $settings = $this->settings();

        return [
            'classes' => array_map(fn (PreservationDocumentClass $class): array => [
                'document_class' => $class->value,
                'suggested_years' => $class->suggestedRetentionYears(),
                'configured_years' => isset($settings?->retention_years[$class->value]) ? (int) $settings->retention_years[$class->value] : null,
                'effective_years' => $this->effective($class)['years'],
                'source' => $this->effective($class)['source'],
            ], PreservationDocumentClass::cases()),
            'confirmed_by_accountant' => $settings?->retention_confirmed_at !== null,
            'confirmed_at' => $settings?->retention_confirmed_at?->toIso8601String(),
            'notes' => $settings?->notes,
            'disclaimer' => 'The suggested periods are commonly cited defaults that this application has not verified against the law. Have the accountant confirm them before relying on them.',
            'timestamp' => [
                'tsa_url' => $settings?->tsa_url,
                'tsa_username_set' => $settings?->tsa_username !== null,
                'tsa_password_set' => $settings?->tsa_password !== null,
                'tsa_provider_name' => $settings?->tsa_provider_name,
                'tsa_declared_qualified' => (bool) $settings?->tsa_declared_qualified,
            ],
        ];
    }

    /**
     * Whether the accountant confirmed the retention periods of the tenant.
     */
    public function confirmed(): bool
    {
        return $this->settings()?->retention_confirmed_at !== null;
    }

    /**
     * Save the RFC 3161 time-stamp authority of the tenant. A missing key keeps the stored value; a null url removes
     * the authority (and its credentials). `tsa_declared_qualified` is the tenant's own declaration.
     *
     * @param  array{tsa_url?: ?string, tsa_username?: ?string, tsa_password?: ?string, tsa_provider_name?: ?string, tsa_declared_qualified?: bool}  $data
     */
    public function updateTimestamp(array $data): PreservationSetting
    {
        $settings = PreservationSetting::firstOrNew(['tenant_id' => $this->tenantContext->getTenantId()]);

        if (array_key_exists('tsa_url', $data) && $data['tsa_url'] === null) {
            $data = ['tsa_url' => null, 'tsa_username' => null, 'tsa_password' => null, 'tsa_provider_name' => null, 'tsa_declared_qualified' => false];
        }

        $settings->fill($data);
        $settings->save();

        return $settings;
    }

    /**
     * Save the periods of the tenant. `confirmed` records that the accountant confirmed them.
     *
     * @param  array<string, int>  $years  years by document class; classes left out keep their current value
     */
    public function update(array $years, bool $confirmed, ?string $notes, User $user): PreservationSetting
    {
        $settings = PreservationSetting::firstOrNew(['tenant_id' => $this->tenantContext->getTenantId()]);
        $settings->retention_years = [...($settings->retention_years ?? []), ...$years];
        $settings->notes = $notes ?? $settings->notes;

        if ($confirmed) {
            $settings->retention_confirmed_at = now();
            $settings->retention_confirmed_by = $user->id;
        } elseif ($years !== []) {
            $settings->retention_confirmed_at = null;
            $settings->retention_confirmed_by = null;
        }

        $settings->save();

        return $settings;
    }

    private function settings(): ?PreservationSetting
    {
        return PreservationSetting::where('tenant_id', $this->tenantContext->getTenantId())->first();
    }
}
