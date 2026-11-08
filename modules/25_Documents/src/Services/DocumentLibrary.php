<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Documents\Exceptions\DuplicateDocumentException;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\Documents\Models\DmsRetentionPolicy;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Writes to the document library. Files go to the private disk before the transaction, because uploads do not belong
 * inside one, and are removed again if it fails. Versions are never changed: a new file is a new version.
 */
final readonly class DocumentLibrary
{
    private const string ModuleCode = 'MODULE_25_DOCUMENTS';

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLedgerInterface $auditLedger,
        private DocumentLinkRegistry $links,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $tagIds
     *
     * @throws DuplicateDocumentException when the same file is already stored and duplicates were not allowed
     */
    public function create(array $attributes, UploadedFile $file, ?string $comment, bool $allowDuplicate, array $tagIds, User $user): DmsDocument
    {
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());

        if (! $allowDuplicate) {
            $this->assertNotDuplicate($hash);
        }

        $stored = $this->store($file);

        try {
            return DB::transaction(function () use ($attributes, $file, $stored, $hash, $comment, $tagIds, $user): DmsDocument {
                $document = DmsDocument::create([
                    ...$attributes,
                    'status' => 'active',
                    'created_by' => $user->id,
                ]);

                $this->applyRetention($document);
                $this->addVersionRow($document, $stored, $file->getClientOriginalName(), (string) $file->getMimeType(), (int) $file->getSize(), $hash, $comment, $user);
                $document->tags()->sync($this->withTenant($tagIds));
                $this->refreshSearchText($document);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);

            throw $exception;
        }
    }

    /**
     * @throws DuplicateDocumentException when the file equals the current version of the document
     */
    public function addVersion(DmsDocument $document, UploadedFile $file, ?string $comment, User $user): DmsDocumentVersion
    {
        $this->assertNotOnHold($document);
        $this->assertNotCheckedOutByOther($document, $user);
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());
        $current = $document->versions()->first();

        if ($current !== null && $current->sha256 === $hash) {
            throw new DuplicateDocumentException((string) $document->id, $hash);
        }

        $stored = $this->store($file);

        try {
            return DB::transaction(function () use ($document, $stored, $file, $hash, $comment, $user): DmsDocumentVersion {
                $locked = DmsDocument::query()->lockForUpdate()->findOrFail($document->id);
                $this->assertNotOnHold($locked);
                $this->assertNotCheckedOutByOther($locked, $user);
                $version = $this->addVersionRow($locked, $stored, $file->getClientOriginalName(), (string) $file->getMimeType(), (int) $file->getSize(), $hash, $comment, $user);
                $this->refreshSearchText($locked);
                $this->audit('DOCUMENT_VERSION_ADDED', $locked, ['version_number' => $version->version_number, 'sha256' => $hash, 'original_name' => $version->original_name]);

                return $version;
            });
        } catch (\Throwable $exception) {
            Storage::disk($stored['disk'])->delete($stored['path']);

            throw $exception;
        }
    }

    /**
     * Brings an attachment in by reference. The file stays where it is and importing twice returns the same document.
     *
     * @throws ValidationException when the file of the attachment is missing
     */
    public function importAttachment(Attachment $attachment, ?string $folderId, User $user): DmsDocument
    {
        $existing = DmsDocumentVersion::query()->where('attachment_id', $attachment->id)->first();

        if ($existing !== null) {
            return DmsDocument::query()->findOrFail($existing->document_id);
        }

        $disk = $attachment->getStorageDisk();
        $stream = Storage::disk($disk)->readStream((string) $attachment->path);

        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['attachment_id' => 'The file of this attachment is missing from storage.']);
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        $hash = hash_final($context);

        return DB::transaction(function () use ($attachment, $folderId, $user, $disk, $hash): DmsDocument {
            $document = DmsDocument::create([
                'folder_id' => $folderId,
                'title' => $attachment->original_name,
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $version = $this->addVersionRow(
                $document,
                ['disk' => $disk, 'path' => (string) $attachment->path],
                (string) $attachment->original_name,
                (string) $attachment->mime_type,
                (int) $attachment->size,
                $hash,
                null,
                $user
            );
            $version->update(['attachment_id' => $attachment->id]);

            $alias = $this->links->aliasOf((string) $attachment->attachable_type);

            if ($alias !== null) {
                $this->link($document, $alias, (string) $attachment->attachable_id, $user);
            }

            $this->refreshSearchText($document);

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $tagIds
     */
    public function update(DmsDocument $document, array $attributes, ?array $tagIds, ?User $user = null): DmsDocument
    {
        $this->assertNotOnHold($document);
        $this->assertNotCheckedOutByOther($document, $user);

        return DB::transaction(function () use ($document, $attributes, $tagIds): DmsDocument {
            $document->update($attributes);

            if (array_key_exists('retention_policy_id', $attributes)) {
                $this->applyRetention($document);
            }

            if ($tagIds !== null) {
                $document->tags()->sync($this->withTenant($tagIds));
            }

            $this->refreshSearchText($document);

            return $document;
        });
    }

    /**
     * @throws ConflictHttpException while a legal hold or the retention period protects the document
     */
    public function delete(DmsDocument $document): void
    {
        $this->assertNotOnHold($document);

        if ($document->isCheckedOut()) {
            throw new ConflictHttpException('This document is checked out: check it in or cancel the check-out first.');
        }

        if ($document->retention_until !== null && $document->retention_until->isFuture()) {
            throw new ConflictHttpException("This document is under retention until {$document->retention_until->toDateString()}.");
        }

        $document->delete();
    }

    /**
     * Place legal hold.
     */
    public function placeLegalHold(DmsDocument $document, string $reason, User $user): DmsDocument
    {
        $before = $document->only(['legal_hold', 'legal_hold_reason']);
        $document->update(['legal_hold' => true, 'legal_hold_reason' => $reason, 'legal_hold_at' => now(), 'legal_hold_by' => $user->id]);
        $this->auditLedger->record('document_legal_hold_placed', $document, $before, $document->only(['legal_hold', 'legal_hold_reason']), self::ModuleCode);

        return $document;
    }

    /**
     * Release legal hold.
     */
    public function releaseLegalHold(DmsDocument $document): DmsDocument
    {
        $before = $document->only(['legal_hold', 'legal_hold_reason']);
        $document->update(['legal_hold' => false, 'legal_hold_reason' => null, 'legal_hold_at' => null, 'legal_hold_by' => null]);
        $this->auditLedger->record('document_legal_hold_released', $document, $before, $document->only(['legal_hold', 'legal_hold_reason']), self::ModuleCode);

        return $document;
    }

    /**
     * @throws ValidationException when the record does not exist in this tenant
     */
    public function link(DmsDocument $document, string $alias, string $recordId, User $user): DmsDocumentLink
    {
        if ($this->links->resolve($alias, $recordId) === null) {
            throw ValidationException::withMessages(['linkable_id' => 'This record does not exist.']);
        }

        $link = DmsDocumentLink::firstOrCreate(
            ['tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'linkable_type' => $alias, 'linkable_id' => $recordId],
            ['created_by' => $user->id]
        );

        if ($link->wasRecentlyCreated) {
            $this->audit('DOCUMENT_LINKED', $document, ['linkable_type' => $alias, 'linkable_id' => $recordId]);
        }

        return $link;
    }

    /**
     * Removes the link between a document and a business record.
     */
    public function unlink(DmsDocument $document, DmsDocumentLink $link): void
    {
        $this->audit('DOCUMENT_UNLINKED', $document, ['linkable_type' => $link->linkable_type, 'linkable_id' => (string) $link->linkable_id]);
        $link->delete();
    }

    /**
     * Takes the document out for editing: until it is checked in or the check-out is cancelled nobody else can add a
     * version, change it or delete it. Asking again as the holder changes nothing.
     *
     * @throws ConflictHttpException when somebody else holds it or a legal hold freezes it
     */
    public function checkOut(DmsDocument $document, User $user, ?string $note): DmsDocument
    {
        return DB::transaction(function () use ($document, $user, $note): DmsDocument {
            $locked = DmsDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertNotOnHold($locked);

            if ($locked->isCheckedOut()) {
                $this->assertNotCheckedOutByOther($locked, $user);

                return $locked;
            }

            $locked->update(['checked_out_by' => $user->id, 'checked_out_at' => now(), 'checkout_note' => $note]);
            $this->audit('DOCUMENT_CHECKED_OUT', $locked, ['note' => $note]);

            return $locked;
        });
    }

    /**
     * Gives the document back, with the edited file as a new version when there is one. A file equal to the current
     * version is refused, and the document stays checked out so the holder can cancel instead.
     *
     * @throws ConflictHttpException when the document is not checked out by the caller
     * @throws DuplicateDocumentException when the file equals the current version
     */
    public function checkIn(DmsDocument $document, User $user, ?UploadedFile $file, ?string $comment): DmsDocument
    {
        $current = DmsDocument::query()->findOrFail($document->id);

        if (! $current->isCheckedOut()) {
            throw new ConflictHttpException('This document is not checked out.');
        }

        $this->assertNotCheckedOutByOther($current, $user);

        if ($file !== null) {
            $this->addVersion($current, $file, $comment, $user);
        }

        return $this->release($document, 'DOCUMENT_CHECKED_IN', ['new_version' => $file !== null]);
    }

    /**
     * Drops a check-out without a new version. The holder does it, or an owner or administrator when the holder is
     * not available.
     *
     * @throws ConflictHttpException when the document is not checked out, or by somebody else and the caller is no manager
     */
    public function cancelCheckOut(DmsDocument $document, User $user, bool $asManager): DmsDocument
    {
        $current = DmsDocument::query()->findOrFail($document->id);

        if (! $current->isCheckedOut()) {
            throw new ConflictHttpException('This document is not checked out.');
        }

        if (! $asManager) {
            $this->assertNotCheckedOutByOther($current, $user);
        }

        return $this->release($document, 'DOCUMENT_CHECK_OUT_CANCELLED', ['held_by' => (string) $current->checked_out_by, 'forced' => $asManager && (string) $current->checked_out_by !== (string) $user->id]);
    }

    /**
     * Records that the file of a version was downloaded: reading a confidential document is part of the audit trail.
     */
    public function recordDownload(DmsDocument $document, DmsDocumentVersion $version): void
    {
        $this->audit('DOCUMENT_DOWNLOADED', $document, ['version_number' => $version->version_number, 'sha256' => $version->sha256]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function release(DmsDocument $document, string $action, array $payload): DmsDocument
    {
        return DB::transaction(function () use ($document, $action, $payload): DmsDocument {
            $locked = DmsDocument::query()->lockForUpdate()->findOrFail($document->id);
            $locked->update(['checked_out_by' => null, 'checked_out_at' => null, 'checkout_note' => null]);
            $this->audit($action, $locked, $payload);

            return $locked;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertNotCheckedOutByOther(DmsDocument $document, ?User $user): void
    {
        if ($document->isCheckedOut() && ($user === null || (string) $document->checked_out_by !== (string) $user->id)) {
            throw new ConflictHttpException('This document is checked out by another user.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(string $action, DmsDocument $document, array $payload): void
    {
        $this->auditLedger->record($action, $document, null, $payload, self::ModuleCode);
    }

    /**
     * Files stored more than once in the tenant, among the documents the caller can see.
     *
     * @param  Collection<int, string>  $visibleDocumentIds
     * @return list<array{sha256: string, document_ids: list<string>}>
     */
    public function duplicateGroups(Collection $visibleDocumentIds): array
    {
        return DmsDocumentVersion::query()
            ->whereIn('document_id', $visibleDocumentIds)
            ->get(['document_id', 'sha256'])
            ->groupBy('sha256')
            ->map(static fn (Collection $versions, string $hash): array => [
                'sha256' => $hash,
                'document_ids' => $versions->pluck('document_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all(),
            ])
            ->filter(static fn (array $group): bool => count($group['document_ids']) > 1)
            ->values()
            ->all();
    }

    /**
     * @throws DuplicateDocumentException
     */
    private function assertNotDuplicate(string $hash): void
    {
        $existing = DmsDocumentVersion::query()->where('sha256', $hash)->first();

        if ($existing !== null) {
            throw new DuplicateDocumentException((string) $existing->document_id, $hash);
        }
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertNotOnHold(DmsDocument $document): void
    {
        if ($document->legal_hold) {
            throw new ConflictHttpException('This document is under legal hold and cannot change.');
        }
    }

    /**
     * @return array{disk: string, path: string}
     */
    private function store(UploadedFile $file): array
    {
        $disk = (string) config('filesystems.private_disk', config('filesystems.default', 'local'));
        $directory = "tenants/{$this->tenantContext->getTenantId()}/dms";
        $name = Str::uuid().'.'.($file->guessExtension() ?: 'bin');

        return ['disk' => $disk, 'path' => (string) $file->storeAs($directory, $name, $disk)];
    }

    /**
     * @param  array{disk: string, path: string}  $stored
     */
    private function addVersionRow(DmsDocument $document, array $stored, string $name, string $mime, int $size, string $hash, ?string $comment, User $user): DmsDocumentVersion
    {
        $number = $document->current_version + 1;
        $version = DmsDocumentVersion::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'version_number' => $number,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'original_name' => $name,
            'mime_type' => $mime,
            'size' => $size,
            'sha256' => $hash,
            'comment' => $comment,
            'uploaded_by' => $user->id,
        ]);

        $document->update(['current_version' => $number]);

        return $version;
    }

    /**
     * The retention date starts from the creation of the document. A policy can be picked by hand or by document type.
     */
    private function applyRetention(DmsDocument $document): void
    {
        $policy = $document->retention_policy_id !== null
            ? DmsRetentionPolicy::query()->find($document->retention_policy_id)
            : DmsRetentionPolicy::query()->where('is_active', true)->whereNotNull('document_type')->where('document_type', $document->document_type)->first();

        $document->update([
            'retention_policy_id' => $policy?->id,
            'retention_until' => $policy === null ? null : CarbonImmutable::parse($document->created_at)->addMonthsNoOverflow($policy->retention_months)->toDateString(),
        ]);
    }

    /**
     * @param  list<string>  $tagIds
     * @return array<string, array{tenant_id: string}>
     */
    private function withTenant(array $tagIds): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();

        return collect($tagIds)->mapWithKeys(static fn (string $id): array => [$id => ['tenant_id' => $tenantId]])->all();
    }

    private function refreshSearchText(DmsDocument $document): void
    {
        $tags = $document->tags()->pluck('name')->all();
        $files = $document->versions()->pluck('original_name')->map(static fn (string $name): string => strtr($name, '._-', '   '))->all();
        $metadata = collect($document->metadata ?? [])->flatten()->filter(static fn (mixed $value): bool => is_scalar($value))->all();

        $document->update(['search_text' => implode(' ', [...$tags, ...$files, ...$metadata])]);
    }
}
