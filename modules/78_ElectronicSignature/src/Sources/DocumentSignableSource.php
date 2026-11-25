<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Sources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Models\DmsDocument;
use Modules\ElectronicSignature\Contracts\SignableDocumentSource;
use Modules\ElectronicSignature\DTOs\SignableDocument;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Models\SignatureEnvelope;

/**
 * Documents of the digital document management module (PDF, XML or any other file): the newest version is signed; the
 * signed file comes back to the library as a new version (see ArchiveSignedDocument).
 */
final class DocumentSignableSource implements SignableDocumentSource
{
    public function type(): SignableType
    {
        return SignableType::Document;
    }

    public function find(string $id): ?Model
    {
        return DmsDocument::query()->find($id);
    }

    public function document(Model $record, string $locale): SignableDocument
    {
        if (! $record instanceof DmsDocument) {
            throw new SignatureException('document_not_found', 404);
        }

        $version = $record->latestVersion()->first() ?? throw new SignatureException('document_has_no_file', 422);
        $content = Storage::disk((string) $version->disk)->get((string) $version->path);

        if ($content === null) {
            throw new SignatureException('document_has_no_file', 422);
        }

        return new SignableDocument(
            $content,
            (string) ($record->title ?? $version->original_name),
            format: self::formatOf((string) $version->original_name, $content),
            fileName: (string) $version->original_name,
        );
    }

    public function onCompleted(Model $record, SignatureEnvelope $envelope): void {}

    public function onDeclined(Model $record, SignatureEnvelope $envelope): void {}

    public function onExpired(Model $record, SignatureEnvelope $envelope): void {}

    /**
     * PDF files are signed as PAdES, XML as XAdES, anything else is wrapped in a CAdES (.p7m) envelope.
     */
    public static function formatOf(string $fileName, string $content): string
    {
        if (str_starts_with($content, '%PDF-')) {
            return 'pades';
        }

        $head = ltrim(substr($content, 0, 200));

        return str_starts_with($head, '<?xml') || str_ends_with(strtolower($fileName), '.xml') ? 'xades' : 'cades';
    }
}
