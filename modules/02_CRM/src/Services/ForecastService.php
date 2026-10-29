<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CRM\Contracts\ForecastServiceInterface;
use Modules\CRM\DTOs\GenerateForecastDTO;
use Modules\CRM\Models\Forecast;
use Modules\CRM\Models\Opportunity;

final readonly class ForecastService implements ForecastServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function generateForecast(GenerateForecastDTO $dto, string $actorUserId): Forecast
    {
        return DB::transaction(function () use ($dto) {
            $query = Opportunity::where('tenant_id', $dto->tenantId);

            if (! empty($dto->userId)) {
                $query->where('owner_user_id', $dto->userId);
            }

            // Approximate period filter if format YYYY-MM
            if (preg_match('/^\d{4}-\d{2}$/', $dto->period)) {
                $query->whereRaw("TO_CHAR(expected_close_date, 'YYYY-MM') = ?", [$dto->period]);
            }

            $opportunities = $query->get();

            $pipelineAmount = 0.0;
            $bestCaseAmount = 0.0;
            $commitAmount = 0.0;
            $closedAmount = 0.0;
            $weightedAmount = 0.0;

            foreach ($opportunities as $opp) {
                $amt = (float) $opp->amount;
                $expected = (float) $opp->expected_revenue;
                $cat = (string) $opp->forecast_category;
                $status = (string) $opp->status;

                $weightedAmount += $expected;

                if ($status === 'won') {
                    $closedAmount += $amt;
                    $commitAmount += $amt;
                    $bestCaseAmount += $amt;
                } else {
                    if ($cat === 'pipeline') {
                        $pipelineAmount += $amt;
                        $bestCaseAmount += $amt;
                    } elseif ($cat === 'best_case') {
                        $bestCaseAmount += $amt;
                    } elseif ($cat === 'commit') {
                        $commitAmount += $amt;
                        $bestCaseAmount += $amt;
                    }
                }
            }

            $quota = (float) $dto->quotaAmount;
            $attainment = $quota > 0.0 ? round(($closedAmount / $quota) * 100.0, 2) : 0.00;

            $forecast = Forecast::updateOrCreate(
                [
                    'tenant_id' => $dto->tenantId,
                    'user_id' => $dto->userId,
                    'period' => $dto->period,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'currency' => $dto->currency,
                    'pipeline_amount' => $pipelineAmount,
                    'best_case_amount' => $bestCaseAmount,
                    'commit_amount' => $commitAmount,
                    'closed_amount' => $closedAmount,
                    'weighted_amount' => $weightedAmount,
                    'quota_amount' => $quota,
                    'attainment_percent' => $attainment,
                    'status' => 'draft',
                ]
            );

            $this->auditLedger->record(
                action: 'CRM_FORECAST_GENERATED',
                model: $forecast,
                payloadBefore: null,
                payloadAfter: $forecast->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $forecast;
        });
    }

    public function lockForecast(Forecast $forecast, string $actorUserId): Forecast
    {
        return DB::transaction(function () use ($forecast) {
            $locked = Forecast::where('id', $forecast->id)->lockForUpdate()->firstOrFail();

            $before = $locked->toArray();
            $locked->update(['status' => 'locked']);

            $this->auditLedger->record(
                action: 'CRM_FORECAST_LOCKED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $locked;
        });
    }
}
