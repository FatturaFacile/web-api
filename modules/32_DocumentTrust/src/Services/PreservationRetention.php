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
        ];
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
