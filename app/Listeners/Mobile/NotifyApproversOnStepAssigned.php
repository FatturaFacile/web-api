<?php

declare(strict_types=1);

namespace App\Listeners\Mobile;

use App\Models\User;
use App\Services\Mobile\MobilePushService;
use App\Services\Mobile\RoleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Workflow\Actions\GenerateRemoteApprovalTokensAction;
use Modules\Workflow\Events\WorkflowStepAssignedEvent;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Throwable;

/**
 * Sends an interactive push (Approve / Reject banner actions) to the assignee(s)
 * of a new workflow step. Failures never block the workflow itself.
 */
final class NotifyApproversOnStepAssigned
{
    private const TITLES = [
        'it' => ['Approvazione richiesta', 'Una nuova richiesta attende la tua decisione.'],
        'de' => ['Genehmigung erforderlich', 'Eine neue Anfrage wartet auf Ihre Entscheidung.'],
        'fr' => ['Approbation requise', 'Une nouvelle demande attend votre décision.'],
        'en' => ['Approval required', 'A new request is waiting for your decision.'],
    ];

    public function __construct(
        private readonly MobilePushService $push,
        private readonly GenerateRemoteApprovalTokensAction $tokens,
    ) {}

    public function handle(WorkflowStepAssignedEvent $event): void
    {
        try {
            $step = WorkflowInstanceStep::withoutGlobalScopes()->with('instance')->find($event->stepId);
            if ($step === null) {
                return;
            }

            foreach ($this->recipients($event) as $user) {
                $links = $this->tokens->execute($step, $user);
                $lang = substr((string) $user->locale, 0, 2);
                [$title, $body] = self::TITLES[$lang] ?? self::TITLES['en'];

                $this->push->sendToUser($user->id, $title, $body, [
                    'type' => 'approval_request',
                    'step_id' => $event->stepId,
                    'instance_id' => $event->instanceId,
                    'approve_url' => $links['approve_url'],
                    'reject_url' => $links['reject_url'],
                ], ['category' => 'APPROVAL_ACTIONS']);
            }
        } catch (Throwable $e) {
            Log::warning('mobile push for workflow step failed: '.$e->getMessage());
        }
    }

    /** @return Collection<int, User> */
    private function recipients(WorkflowStepAssignedEvent $event)
    {
        $users = User::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $event->tenantId));

        if ($event->assignedUserId !== null) {
            return $users->whereKey($event->assignedUserId)->get();
        }

        if ($event->assignedRole !== null) {
            $role = $event->assignedRole;
            $resolver = app(RoleResolver::class);

            return $users->get()->filter(fn (User $u) => $resolver->hasAny($u, [$role], $event->tenantId))->values();
        }

        return collect();
    }
}
