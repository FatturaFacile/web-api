<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Enums\ApprovalStatus;
use Modules\Documents\Models\DmsApprovalDecision;
use Modules\Documents\Models\DmsApprovalRequest;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Models\DmsApprovalTemplateStep;
use Modules\Documents\Models\DmsDocument;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Approval workflows of documents: templates with ordered steps, requests that walk the steps one at a time, and the
 * decision of each approver. The requester never decides on their own request, a rejection ends the request, and the
 * last approval completes it.
 */
final readonly class DocumentApprovalService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private TenantContext $tenantContext, private DocumentAccess $access) {}

    /**
     * Creates a template with its steps.
     *
     * @param  array{name: string, document_type?: string|null, is_active?: bool, steps: list<array{name: string, approver_type: string, approver_user_id?: string|null, approver_role?: string|null}>}  $data
     */
    public function createTemplate(array $data): DmsApprovalTemplate
    {
        return DB::transaction(function () use ($data): DmsApprovalTemplate {
            $template = DmsApprovalTemplate::create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'name' => $data['name'],
                'document_type' => $data['document_type'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->writeSteps($template, $data['steps']);

            return $template->load('steps');
        });
    }

    /**
     * Updates a template. Its steps cannot change while a request is pending on it.
     *
     * @param  array{name?: string, document_type?: string|null, is_active?: bool, steps?: list<array{name: string, approver_type: string, approver_user_id?: string|null, approver_role?: string|null}>}  $data
     *
     * @throws ConflictHttpException when the steps change while a request is pending
     */
    public function updateTemplate(DmsApprovalTemplate $template, array $data): DmsApprovalTemplate
    {
        return DB::transaction(function () use ($template, $data): DmsApprovalTemplate {
            $locked = DmsApprovalTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if (isset($data['steps'])) {
                $this->assertNoPendingRequest($locked);
            }

            $locked->update(array_intersect_key($data, array_flip(['name', 'document_type', 'is_active'])));

            if (isset($data['steps'])) {
                $locked->steps()->delete();
                $this->writeSteps($locked, $data['steps']);
            }

            return $locked->load('steps');
        });
    }

    /**
     * Deletes a template that has no pending request.
     *
     * @throws ConflictHttpException
     */
    public function deleteTemplate(DmsApprovalTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $this->assertNoPendingRequest($template);
            $template->steps()->delete();
            $template->delete();
        });
    }

    /**
     * Sends a document through a template.
     *
     * @throws ConflictHttpException when the template is inactive or the document already has a pending request
     */
    public function request(DmsDocument $document, DmsApprovalTemplate $template, User $requester, ?string $note): DmsApprovalRequest
    {
        return DB::transaction(function () use ($document, $template, $requester, $note): DmsApprovalRequest {
            $lockedDocument = DmsDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! $template->is_active) {
                throw new ConflictHttpException(__('documents_errors.approval.template_inactive'));
            }

            $steps = $template->steps()->count();

            if ($steps === 0) {
                throw new ConflictHttpException(__('documents_errors.approval.template_without_steps'));
            }

            $pending = DmsApprovalRequest::query()->where('document_id', $lockedDocument->id)->where('status', ApprovalStatus::Pending->value)->exists();

            if ($pending) {
                throw new ConflictHttpException(__('documents_errors.approval.already_pending'));
            }

            return DmsApprovalRequest::create([
                'tenant_id' => $lockedDocument->tenant_id,
                'document_id' => $lockedDocument->id,
                'document_version_id' => $lockedDocument->latestVersion?->id,
                'template_id' => $template->id,
                'template_name' => $template->name,
                'status' => ApprovalStatus::Pending,
                'current_step' => 1,
                'total_steps' => $steps,
                'requested_by' => $requester->id,
                'note' => $note,
            ]);
        });
    }

    /**
     * Records the decision of the approver of the current step.
     *
     * @throws ConflictHttpException when the request is no longer pending
     * @throws AccessDeniedHttpException when the user is not the approver of the step, or is the requester
     */
    public function decide(DmsApprovalRequest $request, User $user, bool $approve, ?string $comment): DmsApprovalRequest
    {
        return DB::transaction(function () use ($request, $user, $approve, $comment): DmsApprovalRequest {
            $locked = DmsApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== ApprovalStatus::Pending) {
                throw new ConflictHttpException(__('documents_errors.approval.not_pending'));
            }

            $step = $this->currentStep($locked);

            if ($step === null || (string) $locked->requested_by === (string) $user->id || ! $this->isApprover($step, $user)) {
                throw new AccessDeniedHttpException(__('documents_errors.approval.not_approver'));
            }

            DmsApprovalDecision::create([
                'tenant_id' => $locked->tenant_id,
                'request_id' => $locked->id,
                'step_position' => $step->position,
                'step_name' => $step->name,
                'decided_by' => $user->id,
                'decision' => $approve ? 'approved' : 'rejected',
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            if (! $approve) {
                $locked->update(['status' => ApprovalStatus::Rejected, 'completed_at' => now()]);
            } elseif ($locked->current_step >= $locked->total_steps) {
                $locked->update(['status' => ApprovalStatus::Approved, 'completed_at' => now()]);
            } else {
                $locked->update(['current_step' => $locked->current_step + 1]);
            }

            return $locked;
        });
    }

    /**
     * Cancels a pending request. The requester can, and so can owners and administrators.
     *
     * @throws ConflictHttpException
     * @throws AccessDeniedHttpException
     */
    public function cancel(DmsApprovalRequest $request, User $user): DmsApprovalRequest
    {
        return DB::transaction(function () use ($request, $user): DmsApprovalRequest {
            $locked = DmsApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== ApprovalStatus::Pending) {
                throw new ConflictHttpException(__('documents_errors.approval.not_pending'));
            }

            if ((string) $locked->requested_by !== (string) $user->id && ! $this->access->isManager($user)) {
                throw new AccessDeniedHttpException(__('documents_errors.approval.cannot_cancel'));
            }

            $locked->update(['status' => ApprovalStatus::Cancelled, 'completed_at' => now()]);

            return $locked;
        });
    }

    /**
     * The step waiting for a decision, or null when the request is finished.
     */
    public function currentStep(DmsApprovalRequest $request): ?DmsApprovalTemplateStep
    {
        if ($request->template_id === null) {
            return null;
        }

        return DmsApprovalTemplateStep::query()
            ->where('template_id', $request->template_id)
            ->where('position', $request->current_step)
            ->first();
    }

    /**
     * Whether the user may decide the step: the named user, or any member holding the named role.
     */
    public function isApprover(DmsApprovalTemplateStep $step, User $user): bool
    {
        return match ($step->approver_type) {
            'user' => (string) $step->approver_user_id === (string) $user->id,
            'role' => $step->approver_role !== null && $this->access->roleOf($user) === $step->approver_role,
            default => false,
        };
    }

    /**
     * @param  list<array{name: string, approver_type: string, approver_user_id?: string|null, approver_role?: string|null}>  $steps
     */
    private function writeSteps(DmsApprovalTemplate $template, array $steps): void
    {
        foreach ($steps as $index => $step) {
            DmsApprovalTemplateStep::create([
                'tenant_id' => $template->tenant_id,
                'template_id' => $template->id,
                'position' => $index + 1,
                'name' => $step['name'],
                'approver_type' => $step['approver_type'],
                'approver_user_id' => $step['approver_type'] === 'user' ? ($step['approver_user_id'] ?? null) : null,
                'approver_role' => $step['approver_type'] === 'role' ? ($step['approver_role'] ?? null) : null,
            ]);
        }
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertNoPendingRequest(DmsApprovalTemplate $template): void
    {
        if (DmsApprovalRequest::query()->where('template_id', $template->id)->where('status', ApprovalStatus::Pending->value)->exists()) {
            throw new ConflictHttpException(__('documents_errors.approval.template_in_use'));
        }
    }
}
