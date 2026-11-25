<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\NpsRespondentCategory;
use Modules\SalesBoundedContext\Events\PostDeliverySurveyRecordedEvent;
use Modules\SalesBoundedContext\Models\SbcPostDeliverySurvey;

final readonly class RecordPostDeliverySurveyHandler
{
    public function handle(RecordPostDeliverySurveyCommand $command): SbcPostDeliverySurvey
    {
        $data = $command->payload;
        $nps = max(0, min(10, (int) ($data['nps_score'] ?? 9)));
        $csat = max(1, min(5, (int) ($data['csat_score'] ?? 5)));
        $ces = max(1, min(7, (int) ($data['ces_score'] ?? 6)));

        $classification = match (true) {
            $nps >= 9 => NpsRespondentCategory::Promoter,
            $nps >= 7 => NpsRespondentCategory::Passive,
            default => NpsRespondentCategory::Detractor,
        };

        $detractorAlert = $classification === NpsRespondentCategory::Detractor || $csat <= 2;
        $isoIndex = round((($nps * 10.0) * 0.5) + (($csat * 20.0) * 0.5), 2);

        $survey = DB::transaction(function () use (
            $command,
            $data,
            $nps,
            $csat,
            $ces,
            $classification,
            $detractorAlert,
            $isoIndex,
        ): SbcPostDeliverySurvey {
            return SbcPostDeliverySurvey::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'ddt_invoice_id' => $data['ddt_invoice_id'] ?? null,
                'survey_code' => (string) ($data['survey_code'] ?? 'SRV-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6))),
                'trigger_event' => (string) ($data['trigger_event'] ?? 'post_delivery_ddt'),
                'nps_score' => $nps,
                'csat_score' => $csat,
                'ces_score' => $ces,
                'nps_classification' => $classification,
                'detractor_recovery_alert_triggered' => $detractorAlert,
                'recovery_action_owner' => $detractorAlert ? (string) ($data['recovery_action_owner'] ?? 'Customer Success Manager') : null,
                'recovery_resolution_status' => $detractorAlert ? 'alert_open' : 'none',
                'iso9001_clause_ref' => (string) ($data['iso9001_clause_ref'] ?? 'ISO-9001:2015-9.1.2'),
                'iso9001_satisfaction_index' => $isoIndex,
                'verbatim_feedback' => $data['verbatim_feedback'] ?? null,
            ]);
        });

        PostDeliverySurveyRecordedEvent::dispatch(
            $command->tenantId,
            (string) $survey->id,
            $classification->value,
            $detractorAlert,
        );

        return $survey;
    }
}
