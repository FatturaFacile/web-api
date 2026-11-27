<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\FiduciaryPortal\Models\FiduciaryEntryNote;
use Modules\GeneralLedger\Models\JournalEntry;

final readonly class FiduciaryEntryNoteService
{
    public function __construct(private FiduciaryAuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function listNotes(string $tenantId, ?string $journalEntryId = null, ?string $status = null, ?string $search = null): array
    {
        $query = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('parent_note_id')
            ->with(['journalEntry:id,entry_number,posting_date,description,total_amount,fiduciary_verified', 'replies'])
            ->when($journalEntryId !== null && $journalEntryId !== '', fn ($q) => $q->where('journal_entry_id', $journalEntryId))
            ->when($status !== null && $status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== null && trim($search) !== '', function ($q) use ($search): void {
                $needle = '%'.mb_strtolower(trim($search)).'%';
                $q->where(function ($sub) use ($needle): void {
                    $sub->whereRaw('LOWER(body) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(author_name) LIKE ?', [$needle])
                        ->orWhereHas('journalEntry', fn ($je) => $je->whereRaw('LOWER(entry_number) LIKE ?', [$needle]));
                });
            })
            ->orderByDesc('created_at');

        $notes = $query->get();

        $openCount = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('parent_note_id')
            ->where('status', 'open')
            ->count();

        $resolvedCount = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('parent_note_id')
            ->where('status', 'resolved')
            ->count();

        return [
            'open_count' => $openCount,
            'resolved_count' => $resolvedCount,
            'items' => $notes->map(fn (FiduciaryEntryNote $note): array => $this->formatNote($note))->values()->all(),
        ];
    }

    public function createNote(string $tenantId, string $journalEntryId, string $body, User $actor, ?string $parentNoteId = null): FiduciaryEntryNote
    {
        $entry = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($journalEntryId);

        if ($parentNoteId !== null && $parentNoteId !== '') {
            FiduciaryEntryNote::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('journal_entry_id', $entry->id)
                ->findOrFail($parentNoteId);
        } else {
            $parentNoteId = null;
        }

        $role = $actor->getRoleInTenant($tenantId) ?? 'external_fiduciary';

        $note = FiduciaryEntryNote::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $entry->company_id,
            'journal_entry_id' => (string) $entry->id,
            'parent_note_id' => $parentNoteId,
            'author_user_id' => (string) $actor->getKey(),
            'author_name' => (string) $actor->name,
            'author_role' => $role,
            'body' => trim($body),
            'status' => 'open',
            'created_by' => (string) $actor->getKey(),
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            action: $parentNoteId !== null ? 'note_replied' : 'note_created',
            summary: "Added annotation on entry [{$entry->entry_number}]: ".mb_substr(trim($body), 0, 120),
            actor: $actor,
            targetType: 'fiduciary_entry_note',
            targetId: (string) $note->id,
            targetReference: (string) $entry->entry_number,
            metadata: ['journal_entry_id' => (string) $entry->id, 'parent_note_id' => $parentNoteId],
            auditModel: $note,
        );

        return $note->load(['journalEntry', 'replies']);
    }

    public function resolveNote(string $tenantId, string $noteId, bool $resolved, ?string $comment, User $actor): FiduciaryEntryNote
    {
        $note = FiduciaryEntryNote::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('journalEntry')
            ->findOrFail($noteId);

        $note->update([
            'status' => $resolved ? 'resolved' : 'open',
            'resolved_at' => $resolved ? now() : null,
            'resolved_by' => $resolved ? (string) $actor->getKey() : null,
            'resolution_comment' => $comment,
            'updated_by' => (string) $actor->getKey(),
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            action: $resolved ? 'note_resolved' : 'note_reopened',
            summary: ($resolved ? 'Resolved' : 'Reopened')." annotation on entry [{$note->journalEntry?->entry_number}].",
            actor: $actor,
            targetType: 'fiduciary_entry_note',
            targetId: (string) $note->id,
            targetReference: (string) ($note->journalEntry->entry_number ?? ''),
            metadata: ['status' => $note->status, 'resolution_comment' => $comment],
            auditModel: $note,
        );

        return $note->refresh()->load(['journalEntry', 'replies']);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNote(FiduciaryEntryNote $note): array
    {
        return [
            'id' => (string) $note->id,
            'journal_entry_id' => (string) $note->journal_entry_id,
            'entry_number' => $note->journalEntry?->entry_number,
            'posting_date' => $note->journalEntry?->posting_date?->toDateString(),
            'entry_description' => $note->journalEntry?->description,
            'parent_note_id' => $note->parent_note_id ? (string) $note->parent_note_id : null,
            'author_user_id' => $note->author_user_id ? (string) $note->author_user_id : null,
            'author_name' => (string) $note->author_name,
            'author_role' => (string) $note->author_role,
            'body' => (string) $note->body,
            'status' => (string) $note->status,
            'resolved_at' => $note->resolved_at?->toIso8601String(),
            'resolved_by' => $note->resolved_by ? (string) $note->resolved_by : null,
            'resolution_comment' => $note->resolution_comment,
            'created_at' => $note->created_at?->toIso8601String(),
            'replies' => $note->relationLoaded('replies')
                ? $note->replies->map(fn (FiduciaryEntryNote $reply): array => $this->formatNote($reply))->values()->all()
                : [],
        ];
    }
}
