<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Quality\Models\QmsProcedure;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Document-controlled procedures: draft, submitted for approval, approved (effective), rejected or obsolete. The text
 * of a procedure is a document of the Documents module, referenced by id only.
 *
 * Approval goes through the Workflow module: when the company has an active definition "quality_procedure_approval",
 * submitting starts it and its outcome approves or rejects the procedure (WorkflowApprovalQualityListener). Without
 * the definition the procedure waits for a direct decision by someone other than its author. Approving a new version
 * makes the previous approved version obsolete, so only one version of a code is effective.
 */
final readonly class ProcedureService
{
    public function __construct(private QualityLedger $ledger, private WorkflowEngineInterface $workflow) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $actorId): QmsProcedure
    {
        $this->assertDocument($tenantId, $data['document_id'] ?? null);

        if (QmsProcedure::query()->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['A procedure with this code exists: revise it to create a new version.']]);
        }

        $procedure = QmsProcedure::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'version' => 1,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'document_id' => $data['document_id'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            'author_id' => $this->uuidOrNull($actorId),
            'review_due_date' => $data['review_due_date'] ?? null,
            'status' => 'draft',
        ]);

        $this->ledger->record('QMS_PROCEDURE_DRAFTED', $procedure, ['code' => $procedure->code, 'version' => 1]);

        return $procedure;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsProcedure $procedure, array $data): QmsProcedure
    {
        if (! $procedure->isEditable()) {
            throw ValidationException::withMessages(['status' => ["The procedure is {$procedure->status}: only a draft or rejected procedure can be changed."]]);
        }

        if (array_key_exists('document_id', $data)) {
            $this->assertDocument((string) $procedure->tenant_id, $data['document_id']);
        }

        $procedure->update(array_intersect_key($data, array_flip(['title', 'description', 'document_id', 'owner_id', 'review_due_date'])));

        return $procedure->refresh();
    }

    public function submit(QmsProcedure $procedure, ?string $actorId): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $actorId): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => ["The procedure is {$locked->status}: only a draft or rejected procedure is submitted."]]);
            }

            if ($locked->document_id === null) {
                throw ValidationException::withMessages(['document_id' => ['Link the document of the procedure before submitting it for approval.']]);
            }

            $locked->update(['status' => 'pending_approval', 'submitted_at' => now(), 'rejected_at' => null, 'rejected_by' => null, 'rejection_reason' => null]);

            $hasWorkflow = WorkflowDefinition::query()->where('tenant_id', $locked->tenant_id)->where('code', QmsProcedure::ApprovalWorkflow)->where('is_active', true)->exists();
            if ($hasWorkflow) {
                try {
                    // four_eyes: the engine itself refuses a decision of the author or of whoever submitted the procedure.
                    $instance = $this->workflow->start(new StartWorkflowDTO(
                        workflowCode: QmsProcedure::ApprovalWorkflow,
                        approvable: $locked,
                        contextSnapshot: [
                            'code' => $locked->code,
                            'version' => $locked->version,
                            'title' => $locked->title,
                            'document_id' => $locked->document_id,
                            'four_eyes' => true,
                            'maker_user_ids' => array_values(array_filter([(string) $locked->author_id, (string) $this->uuidOrNull($actorId)])),
                        ],
                        tenantId: (string) $locked->tenant_id,
                        companyId: (string) $locked->tenant_id,
                        initiatorUserId: $this->uuidOrNull($actorId),
                    ));
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['status' => ['The approval workflow cannot be started: '.$exception->getMessage()]]);
                }
                $locked->update(['workflow_instance_id' => (string) $instance->id]);
            }

            $this->ledger->record('QMS_PROCEDURE_SUBMITTED', $locked, ['workflow' => $hasWorkflow]);

            return $locked->refresh();
        });
    }

    /**
     * Direct approval, when no workflow decides. The author cannot approve their own procedure.
     */
    public function approve(QmsProcedure $procedure, ?string $approverId): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $approverId): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            if ($this->isDecidedByWorkflow($locked)) {
                throw ValidationException::withMessages(['status' => ['This procedure is decided by its approval workflow: use the workflow inbox.']]);
            }

            return $this->applyApproval($locked, $approverId);
        });
    }

    public function reject(QmsProcedure $procedure, string $reason, ?string $rejecterId): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $reason, $rejecterId): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            if ($this->isDecidedByWorkflow($locked)) {
                throw ValidationException::withMessages(['status' => ['This procedure is decided by its approval workflow: use the workflow inbox.']]);
            }

            return $this->applyRejection($locked, $reason, $rejecterId);
        });
    }

    /**
     * Applies the approval decided by the workflow engine (called by the listener), for a procedure still pending.
     */
    public function applyWorkflowApproval(QmsProcedure $procedure, ?string $approverId): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $approverId): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            return $this->applyApproval($locked, $approverId);
        });
    }

    public function applyWorkflowRejection(QmsProcedure $procedure, string $reason, ?string $rejecterId): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $reason, $rejecterId): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            return $this->applyRejection($locked, $reason, $rejecterId);
        });
    }

    /**
     * The approval workflow expired: nobody decided in time. Conservative rule: the procedure is neither approved nor
     * rejected (nobody decided); it goes back to draft, as the engine does for the record under approval, so the author
     * can fix and submit it again. Only a procedure still pending is touched.
     */
    public function applyWorkflowExpiry(QmsProcedure $procedure, string $reason): QmsProcedure
    {
        return DB::transaction(function () use ($procedure, $reason): QmsProcedure {
            $locked = QmsProcedure::query()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->update(['status' => 'draft', 'submitted_at' => null]);
            $this->ledger->record('QMS_PROCEDURE_APPROVAL_EXPIRED', $locked, ['code' => $locked->code, 'version' => $locked->version, 'reason' => $reason]);

            return $locked->refresh();
        });
    }

    public function makeObsolete(QmsProcedure $procedure): QmsProcedure
    {
        if ($procedure->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only an approved procedure can be made obsolete.']]);
        }

        $procedure->update(['status' => 'obsolete']);
        $this->ledger->record('QMS_PROCEDURE_OBSOLETE', $procedure, ['code' => $procedure->code, 'version' => $procedure->version]);

        return $procedure->refresh();
    }

    /**
     * A new draft version of an approved procedure (same code, version + 1). The approved one stays effective until the
     * new version is approved.
     */
    public function revise(QmsProcedure $procedure, ?string $actorId): QmsProcedure
    {
        if ($procedure->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only an approved procedure is revised.']]);
        }

        return DB::transaction(function () use ($procedure, $actorId): QmsProcedure {
            $latest = (int) QmsProcedure::query()->where('tenant_id', $procedure->tenant_id)->where('code', $procedure->code)->max('version');
            $draft = QmsProcedure::create([
                'tenant_id' => $procedure->tenant_id,
                'code' => $procedure->code,
                'version' => $latest + 1,
                'title' => $procedure->title,
                'description' => $procedure->description,
                'document_id' => $procedure->document_id,
                'owner_id' => $procedure->owner_id,
                'author_id' => $this->uuidOrNull($actorId),
                'review_due_date' => $procedure->review_due_date,
                'status' => 'draft',
            ]);
            $this->ledger->record('QMS_PROCEDURE_REVISED', $draft, ['code' => $draft->code, 'version' => $draft->version, 'from_version' => $procedure->version]);

            return $draft;
        });
    }

    private function applyApproval(QmsProcedure $locked, ?string $approverId): QmsProcedure
    {
        if ($locked->author_id !== null && $approverId !== null && (string) $locked->author_id === $approverId) {
            throw ValidationException::withMessages(['approved_by' => ['The author of a procedure cannot approve it.']]);
        }

        QmsProcedure::query()
            ->where('tenant_id', $locked->tenant_id)
            ->where('code', $locked->code)
            ->where('status', 'approved')
            ->whereKeyNot($locked->id)
            ->get()
            ->each(function (QmsProcedure $previous): void {
                $previous->update(['status' => 'obsolete']);
                $this->ledger->record('QMS_PROCEDURE_OBSOLETE', $previous, ['superseded' => true, 'version' => $previous->version]);
            });

        $locked->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->uuidOrNull($approverId),
            'effective_date' => $locked->effective_date ?? now()->toDateString(),
        ]);
        $this->ledger->record('QMS_PROCEDURE_APPROVED', $locked, ['code' => $locked->code, 'version' => $locked->version]);

        return $locked->refresh();
    }

    private function applyRejection(QmsProcedure $locked, string $reason, ?string $rejecterId): QmsProcedure
    {
        $locked->update(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $this->uuidOrNull($rejecterId), 'rejection_reason' => $reason]);
        $this->ledger->record('QMS_PROCEDURE_REJECTED', $locked, ['code' => $locked->code, 'version' => $locked->version]);

        return $locked->refresh();
    }

    private function assertPending(QmsProcedure $procedure): void
    {
        if ($procedure->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => ["The procedure is {$procedure->status}, not pending approval."]]);
        }
    }

    private function isDecidedByWorkflow(QmsProcedure $procedure): bool
    {
        if ($procedure->workflow_instance_id === null) {
            return false;
        }

        $instance = WorkflowInstance::query()->find($procedure->workflow_instance_id);

        return $instance !== null && in_array($instance->getAttribute('status'), ['pending', 'in_progress'], true);
    }

    private function assertDocument(string $tenantId, mixed $documentId): void
    {
        if ($documentId === null) {
            return;
        }

        if (! DB::table('dms_documents')->where('tenant_id', $tenantId)->where('id', $documentId)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['document_id' => ['The document does not exist in this company.']]);
        }
    }

    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
