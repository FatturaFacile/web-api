<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\HumanResources\Contracts\CandidateDocumentStorageInterface;
use Modules\HumanResources\DTOs\StoredCandidateDocumentDTO;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores CVs on the private (never public) disk under `tenants/{tenant_id}/recruiting/cvs/{uuid}.{ext}`.
 */
final readonly class PrivateDiskCandidateDocumentStorage implements CandidateDocumentStorageInterface
{
    public function store(UploadedFile $file, string $tenantId): StoredCandidateDocumentDTO
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin'));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $directory = "tenants/{$tenantId}/recruiting/cvs";
        $fileName = Str::uuid7()->toString().'.'.$extension;

        $path = $this->disk()->putFileAs($directory, $file, $fileName);
        if ($path === false) {
            throw new RuntimeException('The CV could not be stored.');
        }

        return new StoredCandidateDocumentDTO(
            storageKey: $path,
            originalName: mb_substr(str_replace(['/', '\\'], '_', $file->getClientOriginalName()), 0, 255) ?: 'cv.'.$extension,
            mimeType: (string) ($file->getMimeType() ?: $file->getClientMimeType()),
            sizeBytes: (int) $file->getSize()
        );
    }

    public function contents(string $storageKey): ?string
    {
        return $this->disk()->exists($storageKey) ? $this->disk()->get($storageKey) : null;
    }

    public function exists(string $storageKey): bool
    {
        return $this->disk()->exists($storageKey);
    }

    public function download(string $storageKey, string $fileName, ?string $mimeType = null): StreamedResponse
    {
        $headers = $mimeType !== null && $mimeType !== '' ? ['Content-Type' => $mimeType] : [];

        return $this->disk()->download($storageKey, $fileName, $headers);
    }

    public function delete(string $storageKey): void
    {
        if ($this->disk()->exists($storageKey)) {
            $this->disk()->delete($storageKey);
        }
    }

    /**
     * @return FilesystemAdapter&Filesystem
     */
    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('recruiting.cv_disk', 'private'));

        return $disk;
    }
}
