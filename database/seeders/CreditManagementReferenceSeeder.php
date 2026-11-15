<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Modules\CreditManagement\Models\CreditDunningStep;
use Modules\CreditManagement\Models\CreditDunningStrategy;
use Modules\CreditManagement\Models\CreditRatingBand;
use Modules\CreditManagement\Models\CreditScorecardFactor;
use Modules\CreditManagement\Services\CreditScoringService;

/**
 * Reference data of the credit management module for every company: the default scorecard, the rating bands and a
 * standard dunning strategy. Idempotent: records are matched on their natural key (code, strategy name and step
 * order), including the ones a company deleted, so a rerun never duplicates or resurrects anything.
 */
final class CreditManagementReferenceSeeder extends Seeder
{
    public const string StrategyName = 'Standard dunning';

    /**
     * Step order, name, days after the due date, channel, template key, fee and whether the step escalates the case.
     *
     * @var list<array{0: int, 1: string, 2: int, 3: string, 4: string, 5: string, 6: bool}>
     */
    private const array Steps = [
        [1, 'Friendly reminder', 7, 'email', 'credit.dunning.friendly_reminder', '0.00', false],
        [2, 'First formal reminder', 15, 'email', 'credit.dunning.first_reminder', '0.00', false],
        [3, 'Second reminder with late fee', 30, 'letter', 'credit.dunning.second_reminder', '10.00', false],
        [4, 'Phone call', 40, 'phone', 'credit.dunning.phone_call', '0.00', true],
        [5, 'Formal notice of default', 60, 'registered_letter', 'credit.dunning.formal_notice', '25.00', true],
        [6, 'Handover to legal collection', 90, 'legal', 'credit.dunning.legal_handover', '0.00', true],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->setTenant($tenant);

            try {
                $this->seedTenant((string) $tenant->id);
            } finally {
                $context->clear();
            }
        }
    }

    private function seedTenant(string $tenantId): void
    {
        foreach (CreditScoringService::DefaultFactors as $index => [$code, $name, $source, $weight]) {
            if (CreditScorecardFactor::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            CreditScorecardFactor::create(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'source' => $source, 'weight' => $weight, 'is_active' => true, 'sort_order' => $index]);
        }

        foreach (CreditScoringService::DefaultBands as [$code, $name, $min, $max, $risk, $multiplier, $ceiling]) {
            if (CreditRatingBand::withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                continue;
            }

            CreditRatingBand::create(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'min_score' => $min, 'max_score' => $max, 'risk_level' => $risk, 'limit_multiplier' => $multiplier, 'limit_ceiling' => $ceiling]);
        }

        $strategy = CreditDunningStrategy::withTrashed()->where('tenant_id', $tenantId)->where('name', self::StrategyName)->first();

        if ($strategy === null) {
            $strategy = CreditDunningStrategy::create([
                'tenant_id' => $tenantId,
                'name' => self::StrategyName,
                'is_default' => ! CreditDunningStrategy::query()->where('tenant_id', $tenantId)->where('is_default', true)->exists(),
                'is_active' => true,
                'description' => 'Reminders by increasing severity from 7 to 90 days after the due date.',
            ]);
        }

        foreach (self::Steps as [$order, $name, $days, $channel, $template, $fee, $escalates]) {
            if (CreditDunningStep::withTrashed()->where('strategy_id', $strategy->id)->where('step_order', $order)->exists()) {
                continue;
            }

            CreditDunningStep::create(['tenant_id' => $tenantId, 'strategy_id' => $strategy->id, 'step_order' => $order, 'name' => $name, 'days_after_due' => $days, 'channel' => $channel, 'template_key' => $template, 'fee_amount' => $fee, 'escalates' => $escalates]);
        }
    }
}
