<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Listeners;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Services\DocumentLibrary;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Events\SignatureEnvelopeCompleted;
use Modules\ElectronicSignature\Services\AuditCertificateService;
use Modules\ElectronicSignature\Services\EnvelopeStorage;
use Modules\ElectronicSignature\Services\SignatureAuditService;
use Modules\ElectronicSignature\Services\SignatureTextResolver;
use Throwable;

/**
 * Archives a completed envelope in the document management module with its audit dossier: the signed file and the
 * certificate of completion (hashes, signers, hash-chained audit trail) become documents with the retention policy of the
 * library, ready for the preservation packages (conservazione a norma, GeBüV). A signed document that came from the
 * library gets the signed file as a new version instead.
 */
final class ArchiveSignedDocument
{
    public function __construct(
        private readonly DocumentLibrary $library,
        private readonly EnvelopeStorage $storage,
        private readonly AuditCertificateService $certificate,
        private readonly SignatureAuditService $audit,
        private readonly SignatureTextResolver $texts,
    ) {}

    public function handle(SignatureEnvelopeCompleted $event): void
    {
        $envelope = $event->envelope->refresh();
        $user = $envelope->created_by !== null ? User::query()->find($envelope->created_by) : null;

        if (! $user instanceof User || $envelope->signed_path === null) {
            return;
        }

        $temporary = [];

        try {
            $extension = $this->storage->extension($envelope);
            $baseName = Str::slug($envelope->title) ?: 'document';
            $signed = $this->temporaryUpload($this->storage->signed($envelope), $baseName.'-signed.'.$extension, $envelope->document_format === 'pades' ? 'application/pdf' : 'application/octet-stream', $temporary);
            $dossier = $this->temporaryUpload($this->certificate->render($envelope->load(['recipients', 'auditLogs'])), $baseName.'-certificate.pdf', 'application/pdf', $temporary);
            $metadata = ['source' => 'electronic_signature', 'envelope_id' => $envelope->id, 'verification_code' => $envelope->verification_code, 'level' => $envelope->required_level->value, 'sha256' => $envelope->signed_sha256];
            $comment = $this->texts->text('signatures.archive.comment', $envelope->locale, ['code' => $envelope->verification_code], $envelope->tenant_id);

            $existing = $envelope->signableKind() === SignableType::Document && $envelope->signable_id !== null ? DmsDocument::query()->find($envelope->signable_id) : null;

            if ($existing instanceof DmsDocument) {
                $this->library->addVersion($existing, $signed, $comment, $user);
            } else {
                $this->library->create(['title' => $envelope->title, 'document_type' => 'signed_document', 'metadata' => $metadata], $signed, $comment, true, [], $user);
            }

            $this->library->create(['title' => $envelope->title.' — '.$this->texts->text('signatures.archive.certificate', $envelope->locale, [], $envelope->tenant_id), 'document_type' => 'signature_certificate', 'metadata' => $metadata], $dossier, $comment, true, [], $user);
            $this->audit->record($envelope, AuditEvent::DocumentArchived, ['type' => 'system'], null, ['retention_years' => (int) config('electronic_signature.archive_retention_years')], $envelope->signed_sha256);
        } catch (Throwable $exception) {
            // The envelope is complete and its files are on the private disk whatever happens to the library.
            Log::error('electronic-signature.archive.failed', ['envelope' => $envelope->id, 'error' => $exception->getMessage()]);
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * @param  list<string>  $temporary
     */
    private function temporaryUpload(string $content, string $name, string $mime, array &$temporary): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sigarc');
        file_put_contents((string) $path, $content);
        $temporary[] = (string) $path;

        return new UploadedFile((string) $path, $name, $mime, null, true);
    }
}
