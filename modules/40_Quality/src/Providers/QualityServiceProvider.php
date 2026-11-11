<?php

declare(strict_types=1);

namespace Modules\Quality\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Quality\Listeners\WorkflowApprovalQualityListener;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsAuditChecklistItem;
use Modules\Quality\Models\QmsAuditFinding;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsNonconformity;
use Modules\Quality\Models\QmsProcedure;
use Modules\Quality\Policies\QualityPolicy;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Registers the routes, policies and workflow listeners of the quality management module.
 */
final class QualityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });

        foreach ([QmsNonconformity::class, QmsCapaAction::class, QmsAudit::class, QmsAuditChecklistItem::class, QmsAuditFinding::class, QmsProcedure::class] as $model) {
            Gate::policy($model, QualityPolicy::class);
        }

        Event::listen(WorkflowInstanceCompletedEvent::class, [WorkflowApprovalQualityListener::class, 'handleCompleted']);
        Event::listen(WorkflowInstanceRejectedEvent::class, [WorkflowApprovalQualityListener::class, 'handleRejected']);
    }
}
