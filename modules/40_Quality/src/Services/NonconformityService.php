<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Quality\Models\QmsAuditFinding;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsNonconformity;

/**
 * Life of a non-conformity: open, investigating, CAPA in progress, closed or cancelled.
 *
 * Closing rules (conservative, to be adapted by the quality manager of the company): every CAPA action must be verified
 * or cancelled, and a major or critical non-conformity needs a root cause and at least one verified corrective action.
 * A non-conformity raised from a manufacturing non-conformance only references it by id: the manufacturing record
 * is never changed from here.
 */
final readonly class NonconformityService
{
    public function __construct(private QualityNumbers $numbers, private QualityLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $actorId): QmsNonconformity
    {
        $this->assertManufacturingReference($tenantId, $data['mfg_non_conformance_id'] ?? null, (string) $data['source']);

        return DB::transaction(function () use ($tenantId, $data, $actorId): QmsNonconformity {
            $nonconformity = QmsNonconformity::create([
                'tenant_id' => $tenantId,
                'number' => $this->numbers->next('qms_nonconformities', 'NC', $tenantId),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'source' => $data['source'],
                'severity' => $data['severity'] ?? 'minor',
                'status' => 'open',
                'detected_at' => $data['detected_at'] ?? now()->toDateString(),
                'detected_by' => $this->uuidOrNull($actorId),
                'owner_id' => $data['owner_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'mfg_non_conformance_id' => $data['mfg_non_conformance_id'] ?? null,
                'audit_finding_id' => $data['audit_finding_id'] ?? null,
                'containment_action' => $data['containment_action'] ?? null,
            ]);

            $this->ledger->record('QMS_NONCONFORMITY_RAISED', $nonconformity, ['source' => $nonconformity->source, 'severity' => $nonconformity->severity]);

            return $nonconformity;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QmsNonconformity $nonconformity, array $data): QmsNonconformity
    {
        $this->assertOpen($nonconformity);
        $nonconformity->update(array_intersect_key($data, array_flip(['title', 'description', 'severity', 'owner_id', 'contact_id', 'containment_action', 'root_cause'])));

        return $nonconformity->refresh();
    }

    public function startInvestigation(QmsNonconformity $nonconformity, ?string $ownerId): QmsNonconformity
    {
        return $this->transition($nonconformity, ['open'], 'investigating', 'QMS_NONCONFORMITY_INVESTIGATING', static fn (): array => array_filter(['owner_id' => $ownerId], static fn (mixed $value): bool => $value !== null));
    }

    public function close(QmsNonconformity $nonconformity, ?string $closureNotes, ?string $actorId): QmsNonconformity
    {
        return DB::transaction(function () use ($nonconformity, $closureNotes, $actorId): QmsNonconformity {
            $locked = QmsNonconformity::query()->whereKey($nonconformity->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            $actions = QmsCapaAction::query()->where('nonconformity_id', $locked->id)->get();
            $unsettled = $actions->reject(static fn (QmsCapaAction $action): bool => in_array($action->status, QmsCapaAction::SettledStatuses, true));
            if ($unsettled->isNotEmpty()) {
                throw ValidationException::withMessages(['status' => ['Every corrective and preventive action must be verified or cancelled before closing: '.$unsettled->pluck('number')->implode(', ').'.']]);
            }

            if (in_array($locked->severity, ['major', 'critical'], true)) {
                if (blank($locked->root_cause)) {
                    throw ValidationException::withMessages(['root_cause' => ['A major or critical non-conformity needs a root cause before it is closed.']]);
                }

                if (! $actions->contains(static fn (QmsCapaAction $action): bool => $action->status === 'verified' && $action->type === 'corrective')) {
                    throw ValidationException::withMessages(['status' => ['A major or critical non-conformity needs at least one verified corrective action before it is closed.']]);
                }
            }

            $locked->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->uuidOrNull($actorId), 'closure_notes' => $closureNotes]);
            QmsAuditFinding::query()->where('nonconformity_id', $locked->id)->update(['status' => 'closed']);
            $this->ledger->record('QMS_NONCONFORMITY_CLOSED', $locked, ['capa_actions' => $actions->count()]);

            return $locked->refresh();
        });
    }

    public function cancel(QmsNonconformity $nonconformity, string $reason): QmsNonconformity
    {
        return $this->transition($nonconformity, ['open', 'investigating', 'capa_in_progress'], 'cancelled', 'QMS_NONCONFORMITY_CANCELLED', static fn (): array => ['closure_notes' => $reason, 'closed_at' => now()]);
    }

    /**
     * Moves a non-conformity to capa_in_progress when its first action is raised (called by the CAPA service).
     */
    public function markCapaInProgress(QmsNonconformity $nonconformity): void
    {
        if (in_array($nonconformity->status, ['open', 'investigating'], true)) {
            $nonconformity->update(['status' => 'capa_in_progress']);
        }
    }

    /**
     * @param  list<string>  $from
     * @param  callable(): array<string, mixed>  $extra
     */
    private function transition(QmsNonconformity $nonconformity, array $from, string $to, string $event, callable $extra): QmsNonconformity
    {
        return DB::transaction(function () use ($nonconformity, $from, $to, $event, $extra): QmsNonconformity {
            $locked = QmsNonconformity::query()->whereKey($nonconformity->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ["The non-conformity is {$locked->status}: expected ".implode(' or ', $from).'.']]);
            }

            $locked->update(['status' => $to, ...$extra()]);
            $this->ledger->record($event, $locked, ['to' => $to]);

            return $locked->refresh();
        });
    }

    private function assertOpen(QmsNonconformity $nonconformity): void
    {
        if (! $nonconformity->isOpen()) {
            throw ValidationException::withMessages(['status' => ["The non-conformity is {$nonconformity->status} and cannot be changed."]]);
        }
    }

    private function assertManufacturingReference(string $tenantId, mixed $reference, string $source): void
    {
        if ($reference === null) {
            return;
        }

        if ($source !== 'manufacturing') {
            throw ValidationException::withMessages(['mfg_non_conformance_id' => ['A manufacturing reference is accepted only for the source manufacturing.']]);
        }

        $exists = DB::table('mfg_quality_non_conformances')->where('tenant_id', $tenantId)->where('id', $reference)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['mfg_non_conformance_id' => ['The manufacturing non-conformance does not exist in this company.']]);
        }
    }

    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Str::isUuid($id) ? $id : null;
    }
}
