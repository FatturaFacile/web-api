<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsComplaint;
use Modules\Quality\Models\QmsNonconformity;

/**
 * Life of a customer complaint: received, investigating (owner, root cause, linked non-conformity and CAPA), replied to
 * the customer and closed; or rejected with the reason when it is not justified. A complaint can be closed only after
 * the customer reply, with a root cause, and once its linked non-conformity is closed or cancelled.
 */
final readonly class ComplaintService
{
    public function __construct(private QualityNumbers $numbers, private QualityLedger $ledger, private NonconformityService $nonconformities) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): QmsComplaint
    {
        return DB::transaction(function () use ($tenantId, $data): QmsComplaint {
            $complaint = QmsComplaint::create([
                ...$this->fields($data),
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next('qms_complaints', 'CMP', $tenantId),
                'received_at' => $data['received_at'] ?? now()->toDateString(),
                'severity' => $data['severity'] ?? 'minor',
                'channel' => $data['channel'] ?? 'email',
                'status' => 'received',
            ]);
            $this->ledger->record('QMS_COMPLAINT_RECEIVED', $complaint, ['severity' => $complaint->severity]);

            return $complaint;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsComplaint $complaint, array $data): QmsComplaint
    {
        $this->assertOpen($complaint);
        $complaint->update($this->fields($data));

        return $complaint->refresh();
    }

    public function investigate(QmsComplaint $complaint, ?string $ownerId): QmsComplaint
    {
        $this->assertStatus($complaint, ['received']);
        $complaint->update(['status' => 'investigating', 'owner_id' => $ownerId ?? $complaint->owner_id]);
        $this->ledger->record('QMS_COMPLAINT_INVESTIGATING', $complaint);

        return $complaint->refresh();
    }

    public function recordRootCause(QmsComplaint $complaint, string $rootCause): QmsComplaint
    {
        $this->assertStatus($complaint, ['investigating', 'replied']);
        $complaint->update(['root_cause' => $rootCause]);

        return $complaint->refresh();
    }

    /**
     * Raises a non-conformity with the source customer complaint from this complaint and links it.
     */
    public function raiseNonconformity(QmsComplaint $complaint, ?string $actorId): QmsComplaint
    {
        $this->assertStatus($complaint, ['received', 'investigating', 'replied']);
        if ($complaint->nonconformity_id !== null) {
            throw ValidationException::withMessages(['nonconformity_id' => [__('quality_errors.complaint.already_linked')]]);
        }

        return DB::transaction(function () use ($complaint, $actorId): QmsComplaint {
            $nonconformity = $this->nonconformities->create($complaint->tenant_id, [
                'title' => __('quality_errors.complaint.nonconformity_title', ['number' => $complaint->number, 'customer' => $complaint->customer_name]),
                'description' => $complaint->description,
                'source' => 'customer_complaint',
                'severity' => $complaint->severity,
                'contact_id' => $complaint->contact_id,
                'owner_id' => $complaint->owner_id,
            ], $actorId);
            $complaint->update(['nonconformity_id' => $nonconformity->id]);
            $this->ledger->record('QMS_COMPLAINT_NONCONFORMITY_RAISED', $complaint, ['nonconformity_id' => $nonconformity->id]);

            return $complaint->refresh();
        });
    }

    /**
     * Links an existing non-conformity and/or CAPA action of the company.
     */
    public function link(QmsComplaint $complaint, ?string $nonconformityId, ?string $capaActionId): QmsComplaint
    {
        $this->assertStatus($complaint, ['received', 'investigating', 'replied']);
        $updates = [];
        if ($nonconformityId !== null) {
            QmsNonconformity::query()->where('tenant_id', $complaint->tenant_id)->findOrFail($nonconformityId);
            $updates['nonconformity_id'] = $nonconformityId;
        }
        if ($capaActionId !== null) {
            $capa = QmsCapaAction::query()->where('tenant_id', $complaint->tenant_id)->findOrFail($capaActionId);
            $linked = $updates['nonconformity_id'] ?? $complaint->nonconformity_id;
            if ($linked !== null && $capa->nonconformity_id !== null && $capa->nonconformity_id !== $linked) {
                throw ValidationException::withMessages(['capa_action_id' => [__('quality_errors.complaint.capa_other_nonconformity')]]);
            }
            $updates['capa_action_id'] = $capaActionId;
        }
        $complaint->update($updates);
        $this->ledger->record('QMS_COMPLAINT_LINKED', $complaint, $updates);

        return $complaint->refresh();
    }

    public function reply(QmsComplaint $complaint, string $replyText, ?string $actorId): QmsComplaint
    {
        $this->assertStatus($complaint, ['investigating', 'replied']);
        $complaint->update(['status' => 'replied', 'reply_text' => $replyText, 'replied_at' => now(), 'replied_by' => $actorId]);
        $this->ledger->record('QMS_COMPLAINT_REPLIED', $complaint);

        return $complaint->refresh();
    }

    public function reject(QmsComplaint $complaint, string $reason, ?string $actorId): QmsComplaint
    {
        $this->assertStatus($complaint, ['received', 'investigating']);
        $complaint->update(['status' => 'rejected', 'closure_notes' => $reason, 'closed_at' => now(), 'closed_by' => $actorId]);
        $this->ledger->record('QMS_COMPLAINT_REJECTED', $complaint);

        return $complaint->refresh();
    }

    public function close(QmsComplaint $complaint, ?string $closureNotes, ?string $actorId): QmsComplaint
    {
        $this->assertStatus($complaint, ['replied']);
        if (blank($complaint->root_cause)) {
            throw ValidationException::withMessages(['root_cause' => [__('quality_errors.complaint.root_cause_required')]]);
        }
        if ($complaint->nonconformity_id !== null) {
            $status = QmsNonconformity::query()->whereKey($complaint->nonconformity_id)->value('status');
            if (in_array($status, QmsNonconformity::OpenStatuses, true)) {
                throw ValidationException::withMessages(['nonconformity_id' => [__('quality_errors.complaint.nonconformity_open')]]);
            }
        }
        $complaint->update(['status' => 'closed', 'closure_notes' => $closureNotes, 'closed_at' => now(), 'closed_by' => $actorId]);
        $this->ledger->record('QMS_COMPLAINT_CLOSED', $complaint);

        return $complaint->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(['customer_name', 'contact_id', 'received_at', 'channel', 'reference', 'product_description', 'description', 'severity', 'owner_id']));
    }

    private function assertOpen(QmsComplaint $complaint): void
    {
        if (! $complaint->isOpen()) {
            throw ValidationException::withMessages(['status' => [__('quality_errors.complaint.locked', ['status' => $complaint->status])]]);
        }
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertStatus(QmsComplaint $complaint, array $expected): void
    {
        if (! in_array($complaint->status, $expected, true)) {
            throw ValidationException::withMessages(['status' => [__('quality_errors.complaint.status_expected', ['status' => $complaint->status, 'expected' => implode(', ', $expected)])]]);
        }
    }
}
