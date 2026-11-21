<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;

/**
 * Reads the current file of a DMS document (Mod 007) for the extraction pipeline. The version query is tenant scoped.
 */
final class DmsSourceReader
{
    /**
     * @return array{contents: string, mime_type: string, file_name: string}
     *
     * @throws OcrExtractionException when the document has no stored file
     */
    public function read(string $documentId): array
    {
        $version = DmsDocumentVersion::query()->where('document_id', $documentId)->orderByDesc('version_number')->first();

        if ($version === null) {
            throw new OcrExtractionException('The DMS document has no file version.');
        }

        $contents = Storage::disk($version->disk)->get($version->path);

        if ($contents === null) {
            throw new OcrExtractionException('The file of the DMS document is missing from storage.');
        }

        return [
            'contents' => $contents,
            'mime_type' => $this->mimeType($contents, (string) ($version->mime_type ?? '')),
            'file_name' => (string) $version->original_name,
        ];
    }

    /**
     * The MIME type from the content when it is certain (PDF, XML, PNG, JPEG, WEBP), else the stored one.
     */
    public function mimeType(string $contents, string $stored): string
    {
        $head = ltrim(substr($contents, 0, 64), "\xEF\xBB\xBF \t\r\n");

        return match (true) {
            str_starts_with($head, '%PDF') => 'application/pdf',
            str_starts_with($head, '<?xml') || str_starts_with($head, '<p:Fattura') || str_starts_with($head, '<FatturaElettronica') => 'application/xml',
            str_starts_with($contents, "\x89PNG") => 'image/png',
            str_starts_with($contents, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($contents, 'RIFF') && substr($contents, 8, 4) === 'WEBP' => 'image/webp',
            default => $stored !== '' ? $stored : 'application/octet-stream',
        };
    }
}
