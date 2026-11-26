<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Modules\Workflow\Contracts\ApprovableDocument;
use Modules\Workflow\Contracts\ReactsToWorkflowOutcome;
use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Tells the consumer document how an approval ended. Both consumer contracts are honoured: the legacy
 * `ReactsToWorkflowOutcome` (string outcome, only `approved`/`rejected`/`cancelled`) and the Mod 006
 * {@see ApprovableDocument} (typed outcome, also `withdrawn` and `changes_requested`).
 *
 * On approval the key attributes of the document are stored on the instance: a later edit of the amount, supplier or cost
 * centre is compared with them (see {@see WorkflowLifecycleService::invalidateOnEdit()}).
 */
final readonly class ApprovalOutcomeNotifier
{
    /**
     * Inject the registry.
     */
    public function __construct(private ApprovableRegistry $registry) {}

    /**
     * Applies the outcome on the approvable of the instance, if it has adopted a contract.
     */
    public function notify(WorkflowInstance $instance, ApprovalOutcome|string $outcome): void
    {
        $typed = $outcome instanceof ApprovalOutcome ? $outcome : ApprovalOutcome::fromEngine($outcome);
        $approvable = $instance->approvable;

        if ($approvable === null) {
            return;
        }

        if ($typed === ApprovalOutcome::Approved) {
            $instance->forceFill(['approved_snapshot' => $this->registry->attributesOf($approvable)])->save();
        }

        if ($approvable instanceof ReactsToWorkflowOutcome) {
            // Legacy consumers only know three outcomes; a withdrawal or a request for changes ends like a rejection.
            $approvable->onWorkflowOutcome($instance, match ($typed) {
                ApprovalOutcome::Approved => 'approved',
                ApprovalOutcome::Rejected, ApprovalOutcome::ChangesRequested => 'rejected',
                default => 'cancelled',
            });
        } elseif ($approvable instanceof ApprovableDocument) {
            $approvable->onApprovalCompleted($typed);
        }
    }
}
