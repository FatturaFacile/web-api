<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\RecruitingSettings;

/**
 * @mixin RecruitingSettings
 */
final class RecruitingSettingsResource extends JsonResource
{
    public function __construct(RecruitingSettings $settings, private readonly string $companyName)
    {
        parent::__construct($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RecruitingSettings $settings */
        $settings = $this->resource;

        return [
            'careers' => [
                'enabled' => (bool) $settings->careers_enabled,
                'slug' => $settings->careers_slug,
                'company_name' => $settings->company_name !== null && $settings->company_name !== '' ? $settings->company_name : $this->companyName,
                'intro' => (object) ((array) ($settings->intro ?? [])),
                'privacy_notice' => (object) ((array) ($settings->privacy_notice ?? [])),
                'privacy_policy_url' => $settings->privacy_policy_url,
                'consent_text_version' => (string) $settings->consent_text_version,
            ],
            'retention' => [
                'default_months' => (int) $settings->retention_default_months,
            ],
            'pipeline' => [
                'stages' => $settings->pipelineStages(),
            ],
        ];
    }
}
