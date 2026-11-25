<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\CustomerSatisfaction\Console\EnqueueSemiAnnualSurveysCommand;
use Modules\CustomerSatisfaction\Console\ProcessSurveyDispatchesCommand;
use Modules\CustomerSatisfaction\Contracts\SentimentAnalyzerInterface;
use Modules\CustomerSatisfaction\Contracts\SurveyMessageGatewayInterface;
use Modules\CustomerSatisfaction\Listeners\ScheduleSurveyOnServiceEvents;
use Modules\CustomerSatisfaction\Models\DetractorRecoveryCase;
use Modules\CustomerSatisfaction\Models\Iso9001SatisfactionReport;
use Modules\CustomerSatisfaction\Models\SurveyDispatch;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use Modules\CustomerSatisfaction\Models\SurveyTemplate;
use Modules\CustomerSatisfaction\Policies\SatisfactionPolicy;
use Modules\CustomerSatisfaction\Services\DefaultSurveyMessageGateway;
use Modules\CustomerSatisfaction\Services\LexiconSentimentAnalyzer;
use Modules\ServiceManagement\Events\TicketClosedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;

/**
 * Mod 245 — NPS / CSAT / CES customer satisfaction surveys, closed-loop detractor recovery and ISO 9001 § 9.1.2 reporting (Issue #491).
 */
final class CustomerSatisfactionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SentimentAnalyzerInterface::class, LexiconSentimentAnalyzer::class);
        $this->app->bind(SurveyMessageGatewayInterface::class, DefaultSurveyMessageGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        foreach ([SurveyTemplate::class, SurveyDispatch::class, SurveyResponse::class, DetractorRecoveryCase::class, Iso9001SatisfactionReport::class] as $model) {
            Gate::policy($model, SatisfactionPolicy::class);
        }

        if (class_exists(TicketClosedEvent::class)) {
            Event::listen(TicketClosedEvent::class, [ScheduleSurveyOnServiceEvents::class, 'handleTicketClosed']);
        }

        if (class_exists(WorkOrderCompletedEvent::class)) {
            Event::listen(WorkOrderCompletedEvent::class, [ScheduleSurveyOnServiceEvents::class, 'handleWorkOrderCompleted']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessSurveyDispatchesCommand::class, EnqueueSemiAnnualSurveysCommand::class]);
        }
    }
}
