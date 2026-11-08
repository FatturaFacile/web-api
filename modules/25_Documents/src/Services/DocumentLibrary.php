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
                $version = $this->addVersionRow($locked, $stored, $file->getClientOriginalName(), (string) $file->getMimeType(), (int) $file->getSize(), $hash, $comment, $user);
                $this->refreshSearchText($locked);

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
    public function update(DmsDocument $document, array $attributes, ?array $tagIds): DmsDocument
    {
        $this->assertNotOnHold($document);

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

        return DmsDocumentLink::firstOrCreate(
            ['tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'linkable_type' => $alias, 'linkable_id' => $recordId],
            ['created_by' => $user->id]
        );
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
