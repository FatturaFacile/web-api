<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Per-tenant lead management configuration (rating thresholds and duplicate handling).
 */
final class LeadSetting extends BaseEnterpriseModel
{
    public const array DEDUP_MODES = ['auto_merge', 'notify'];

    /**
     * @var array<string, int|float|string>
     */
    public const array DEFAULTS = [
        'warm_threshold' => 40,
        'hot_threshold' => 70,
        'auto_qualify_threshold' => 80,
        'dedup_mode' => 'notify',
        'dedup_levenshtein_max' => 2,
        'dedup_similarity_min' => 0.85,
        'default_country' => 'IT',
    ];

    protected $table = 'crm_lead_settings';

    protected function casts(): array
    {
        return [
            'warm_threshold' => 'integer',
            'hot_threshold' => 'integer',
            'auto_qualify_threshold' => 'integer',
            'dedup_levenshtein_max' => 'integer',
            'dedup_similarity_min' => 'float',
        ];
    }

    /**
     * Returns the stored settings of the tenant, or an unsaved instance carrying the defaults.
     */
    public static function forTenant(string $tenantId): self
    {
        $settings = self::query()->where('tenant_id', $tenantId)->first();

        if ($settings instanceof self) {
            return $settings;
        }

        $settings = new self(self::DEFAULTS);
        $settings->tenant_id = $tenantId;

        return $settings;
    }

    public function ratingFor(int $score): string
    {
        return match (true) {
            $score >= $this->hot_threshold => 'hot',
            $score >= $this->warm_threshold => 'warm',
            default => 'cold',
        };
    }

    public function isAutoMerge(): bool
    {
        return $this->dedup_mode === 'auto_merge';
    }
}
