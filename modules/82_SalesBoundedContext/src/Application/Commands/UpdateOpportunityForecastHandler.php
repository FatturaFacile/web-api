<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\ForecastCategory;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\OpportunityForecastCommittedEvent;
use Modules\SalesBoundedContext\Models\SbcOpportunityForecast;

final readonly class UpdateOpportunityForecastHandler
{
    public function handle(UpdateOpportunityForecastCommand $command): SbcOpportunityForecast
    {
        $data = $command->payload;
        $stage = (string) ($data['kanban_stage'] ?? 'qualification');
        $probability = (float) ($data['win_probability_pct'] ?? match ($stage) {
            'closed_won' => 100.0,
            'negotiation' => 75.0,
            'proposal' => 50.0,
            'qualification' => 25.0,
            'closed_lost' => 0.0,
            default => 15.0,
        });

        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));
        $gross = DecimalMoney::of((float) ($data['gross_amount'] ?? 0), $currency);
        $weighted = $gross->weighted($probability);
        $quotaTarget = DecimalMoney::of((float) ($data['sales_quota_target'] ?? 100000), $currency);

        $forecastCategory = isset($data['forecast_category'])
            ? ForecastCategory::from((string) $data['forecast_category'])
            : match (true) {
                $stage === 'closed_won' => ForecastCategory::ClosedWon,
                $stage === 'closed_lost' => ForecastCategory::Omitted,
                $probability >= 70.0 => ForecastCategory::Commit,
                $probability >= 45.0 => ForecastCategory::BestCase,
                default => ForecastCategory::Pipeline,
            };

        $attainment = $quotaTarget->toFloat() > 0
            ? round(($weighted->toFloat() / $quotaTarget->toFloat()) * 100.0, 2)
            : 0.0;

        $opportunity = DB::transaction(function () use ($command, $data, $stage, $probability, $currency, $gross, $weighted, $quotaTarget, $forecastCategory, $attainment): SbcOpportunityForecast {
            $record = $command->opportunityId !== null
                ? SbcOpportunityForecast::query()->where('tenant_id', $command->tenantId)->findOrFail($command->opportunityId)
                : new SbcOpportunityForecast(['tenant_id' => $command->tenantId]);

            $record->fill([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? $record->dossier_id,
                'lead_id' => $data['lead_id'] ?? $record->lead_id,
                'opportunity_code' => (string) ($data['opportunity_code'] ?? $record->opportunity_code ?? 'OPP-'.strtoupper(Str::random(6))),
                'title' => (string) ($data['title'] ?? $record->title),
                'kanban_stage' => $stage,
                'win_probability_pct' => number_format($probability, 2, '.', ''),
                'gross_amount' => $gross->roundHalfUp(),
                'weighted_amount' => $weighted->roundHalfUp(),
                'currency' => $currency,
                'forecast_category' => $forecastCategory,
                'sales_quota_target' => $quotaTarget->roundHalfUp(),
                'quota_attainment_pct' => number_format($attainment, 2, '.', ''),
                'owner_name' => $data['owner_name'] ?? $record->owner_name ?? 'Lukas Meier',
                'expected_close_date' => $data['expected_close_date'] ?? $record->expected_close_date ?? now()->addDays(30)->toDateString(),
            ]);
            $record->save();

            return $record;
        });

        OpportunityForecastCommittedEvent::dispatch(
            $command->tenantId,
            (string) $opportunity->id,
            $stage,
            $weighted->roundHalfUp(),
            $forecastCategory->value,
        );

        return $opportunity;
    }
}
