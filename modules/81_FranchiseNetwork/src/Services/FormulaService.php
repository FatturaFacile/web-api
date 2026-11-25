<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Domain\Enums\StartupMode;
use Modules\FranchiseNetwork\Domain\Support\RoyaltyCalculator;
use Modules\FranchiseNetwork\Domain\Support\TieredRateCalculator;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Models\FranchiseFormula;

/**
 * Royalty and marketing fund formulas. The presets (null tenant, seeded) are read-only: a franchisor clones one and tunes the copy.
 */
final class FormulaService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?FranchiseFormula $formula, ?string $userId): FranchiseFormula
    {
        if ($formula !== null && $formula->tenant_id === null) {
            throw new FranchiseNetworkException('formula_preset_read_only', 403);
        }

        TieredRateCalculator::assertValid($data['royalty_tiers'] ?? $formula->royalty_tiers ?? []);
        TieredRateCalculator::assertValid($data['marketing_tiers'] ?? $formula->marketing_tiers ?? []);
        $this->assertStartup([...($formula?->only(['startup_mode', 'startup_months']) ?? []), ...$data]);

        $attributes = collect($data)->only([
            'code', 'labels', 'descriptions', 'royalty_tiers', 'marketing_tiers', 'minimum_guarantee', 'startup_months', 'startup_mode',
            'startup_reduction_percent', 'ramp_start_percent', 'startup_applies_to_marketing', 'currency', 'is_active',
        ])->all();

        if ($formula === null) {
            return FranchiseFormula::query()->create([...$attributes, 'is_preset' => false, 'created_by' => $userId])->refresh();
        }

        $formula->update([...$attributes, 'updated_by' => $userId]);

        return $formula->refresh();
    }

    public function cloneFrom(FranchiseFormula $source, string $code, ?string $userId): FranchiseFormula
    {
        return FranchiseFormula::query()->create([
            ...$source->only(['labels', 'descriptions', 'royalty_tiers', 'marketing_tiers', 'minimum_guarantee', 'startup_months', 'startup_mode', 'startup_reduction_percent', 'ramp_start_percent', 'startup_applies_to_marketing', 'currency']),
            'code' => $code,
            'is_preset' => false,
            'is_active' => true,
            'created_by' => $userId,
        ])->refresh();
    }

    /**
     * Royalty and marketing fund of a hypothetical month, for the formula designer.
     *
     * @return array<string, mixed>
     */
    public function simulate(FranchiseFormula $formula, string|float $netSales, ?string $openedOn, string $period): array
    {
        return RoyaltyCalculator::calculate($netSales, $this->asArray($formula), $openedOn === null ? null : CarbonImmutable::parse($openedOn), $period);
    }

    /**
     * @return array{royalty_tiers: list<array<string, mixed>>, marketing_tiers: list<array<string, mixed>>, minimum_guarantee: string, startup_months: int, startup_mode: string, startup_reduction_percent: string, ramp_start_percent: string, startup_applies_to_marketing: bool}
     */
    public function asArray(FranchiseFormula $formula): array
    {
        return [
            'royalty_tiers' => $formula->royalty_tiers,
            'marketing_tiers' => $formula->marketing_tiers,
            'minimum_guarantee' => (string) $formula->minimum_guarantee,
            'startup_months' => (int) $formula->startup_months,
            'startup_mode' => $formula->startup_mode->value,
            'startup_reduction_percent' => (string) $formula->startup_reduction_percent,
            'ramp_start_percent' => (string) $formula->ramp_start_percent,
            'startup_applies_to_marketing' => (bool) $formula->startup_applies_to_marketing,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertStartup(array $data): void
    {
        $mode = $data['startup_mode'] instanceof StartupMode ? $data['startup_mode'] : StartupMode::tryFrom((string) ($data['startup_mode'] ?? 'none'));

        if ($mode !== null && $mode !== StartupMode::None && (int) ($data['startup_months'] ?? 0) < 1) {
            throw new FranchiseNetworkException('startup_months_required');
        }
    }
}
