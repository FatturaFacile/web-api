<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Support\RecruitingDefaults;

/**
 * Per-tenant recruiting configuration: public careers page, retention and pipeline.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $careers_slug
 * @property bool $careers_enabled
 * @property string|null $company_name
 * @property array<string, string>|null $intro
 * @property array<string, string>|null $privacy_notice
 * @property string|null $privacy_policy_url
 * @property string $consent_text_version
 * @property int $retention_default_months
 * @property array<string, array{enabled?: bool, auto_email?: bool, labels?: array<string, string>}>|null $pipeline
 */
final class RecruitingSettings extends BaseEnterpriseModel
{
    protected $table = 'rec_settings';

    protected function casts(): array
    {
        return [
            'careers_enabled' => 'boolean',
            'intro' => 'array',
            'privacy_notice' => 'array',
            'retention_default_months' => 'integer',
            'pipeline' => 'array',
        ];
    }

    /**
     * Unsaved settings with the module defaults, used for tenants that never configured recruiting.
     */
    public static function defaults(?string $tenantId, ?string $companyName): self
    {
        $settings = new self;
        $settings->forceFill([
            'tenant_id' => $tenantId,
            'careers_slug' => null,
            'careers_enabled' => false,
            'company_name' => $companyName,
            'intro' => [],
            'privacy_notice' => [],
            'privacy_policy_url' => null,
            'consent_text_version' => 'v1',
            'retention_default_months' => (int) config('recruiting.retention.default_months', 6),
            'pipeline' => [],
        ]);

        return $settings;
    }

    /**
     * All seven stages in pipeline order, merged with the defaults.
     *
     * @return list<array{stage: string, enabled: bool, auto_email: bool, labels: array<string, string>}>
     */
    public function pipelineStages(): array
    {
        return array_map(fn (ApplicationStage $stage): array => [
            'stage' => $stage->value,
            'enabled' => $this->stageEnabled($stage),
            'auto_email' => $this->stageAutoEmail($stage),
            'labels' => $this->stageLabels($stage),
        ], ApplicationStage::cases());
    }

    public function stageEnabled(ApplicationStage $stage): bool
    {
        if (! $stage->canBeDisabled()) {
            return true;
        }

        return (bool) ($this->stageConfig($stage)['enabled'] ?? true);
    }

    public function stageAutoEmail(ApplicationStage $stage): bool
    {
        return (bool) ($this->stageConfig($stage)['auto_email'] ?? RecruitingDefaults::autoEmail($stage));
    }

    /**
     * @return array<string, string>
     */
    public function stageLabels(ApplicationStage $stage): array
    {
        $custom = array_filter(
            (array) ($this->stageConfig($stage)['labels'] ?? []),
            static fn (string $label): bool => trim($label) !== ''
        );

        return array_merge(RecruitingDefaults::stageLabels($stage), array_intersect_key($custom, array_flip(RecruitingLocale::values())));
    }

    public function stageLabel(ApplicationStage $stage, RecruitingLocale $locale): string
    {
        return $this->stageLabels($stage)[$locale->value];
    }

    /**
     * Localised careers text (`intro` or `privacy_notice`): requested locale, then `en`, then the first available.
     */
    public function localizedText(string $field, ?string $locale): string
    {
        $texts = array_filter(
            (array) ($this->getAttribute($field) ?? []),
            static fn (mixed $text): bool => is_string($text) && $text !== ''
        );

        if ($locale !== null && isset($texts[$locale])) {
            return (string) $texts[$locale];
        }

        if (isset($texts[RecruitingLocale::En->value])) {
            return (string) $texts[RecruitingLocale::En->value];
        }

        $first = reset($texts);

        return $first === false ? '' : (string) $first;
    }

    /**
     * @return array{enabled?: bool, auto_email?: bool, labels?: array<string, string>}
     */
    private function stageConfig(ApplicationStage $stage): array
    {
        $config = ((array) ($this->pipeline ?? []))[$stage->value] ?? [];

        return $config;
    }
}
