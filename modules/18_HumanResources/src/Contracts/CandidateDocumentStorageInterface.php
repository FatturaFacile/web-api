<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts;

use Illuminate\Http\UploadedFile;
use Modules\HumanResources\DTOs\StoredCandidateDocumentDTO;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private storage of candidate documents. Stands in for the document management of Mod 007.
 */
interface CandidateDocumentStorageInterface
{
    public function store(UploadedFile $file, string $tenantId): StoredCandidateDocumentDTO;

    public function contents(string $storageKey): ?string;

    public function exists(string $storageKey): bool;

    public function download(string $storageKey, string $fileName, ?string $mimeType = null): StreamedResponse;

    public function delete(string $storageKey): void;
}
