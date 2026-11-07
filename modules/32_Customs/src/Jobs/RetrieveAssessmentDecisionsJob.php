<?php

declare(strict_types=1);

namespace Modules\Customs\Jobs;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Customs\Models\CustomsSettings;
use Modules\Customs\Services\AssessmentRetrievalService;

/**
 * The nightly download of the assessment decisions of a company that opted in.
 */
final class RetrieveAssessmentDecisionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(AssessmentRetrievalService $retrieval): void
    {
        $settings = CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantId)->first();
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $settings === null || ! $settings->auto_retrieve) {
            return;
        }

        $to = CarbonImmutable::today();

        $retrieval->run($tenant, $to->subDays((int) config('customs.retrieval_window_days')), $to);
    }
}
