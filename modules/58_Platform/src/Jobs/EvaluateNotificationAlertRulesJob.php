<?php

declare(strict_types=1);

namespace Modules\Platform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Platform\Services\AlertRuleEvaluator;

/**
 * Evaluates the notification alert rules of one tenant.
 */
final class EvaluateNotificationAlertRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(AlertRuleEvaluator $evaluator): void
    {
        $evaluator->evaluate($this->tenantId);
    }
}
