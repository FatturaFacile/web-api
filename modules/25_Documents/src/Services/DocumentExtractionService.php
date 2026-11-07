<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\User;
use Enterprise\AiGateway\Contracts\DocumentExtractorInterface;
use Enterprise\AiGateway\Contracts\TextRecognizerInterface;
use Enterprise\AiGateway\DTOs\ExtractionRequest;
use Enterprise\AiGateway\Exceptions\ExternalAiNotAllowedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Enums\ExtractionStatus;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsExtraction;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Asks the extractor for a proposal and keeps it until a person decides. Confirming writes the reviewed values to the
 * metadata of the document and nothing else: no entry, invoice or payment is ever created from here.
 */
final readonly class DocumentExtractionService
{
    public function __construct(
        private DocumentExtractorInterface $extractor,
        private TextRecognizerInterface $recognizer,
    ) {}

    /**
     * @throws ExternalAiNotAllowedException when the driver is external and the tenant did not enable it
     */
    public function propose(DmsDocument $document, User $user): DmsExtraction
    {
        $version = $document->versions()->firstOrFail();
        $text = $this->recognizer->textOf((string) Storage::disk($version->disk)->get($version->path), $version->mime_type);
        $attributes = [
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'requested_by' => $user->id,
        ];

        if ($text === null || trim($text) === '') {
            return DmsExtraction::create([...$attributes, 'status' => ExtractionStatus::Failed, 'failure_reason' => 'No text could be read: this format needs an OCR engine.']);
        }

        try {
            $result = $this->extractor->extract(new ExtractionRequest((string) $document->tenant_id, $text, $document->document_type));
        } catch (RuntimeException $exception) {
            if ($exception instanceof ExternalAiNotAllowedException) {
                throw $exception;
            }

            return DmsExtraction::create([...$attributes, 'status' => ExtractionStatus::Failed, 'failure_reason' => mb_substr($exception->getMessage(), 0, 500)]);
        }

        return DmsExtraction::create([...$attributes, 'status' => ExtractionStatus::Proposed, 'driver' => $result->driver, 'proposed_fields' => $result->fields]);
    }

    /**
     * @param  array<string, string|null>  $fields  the values as reviewed by the person
     *
     * @throws ConflictHttpException when the proposal was already decided
     */
    public function confirm(DmsExtraction $extraction, array $fields, User $user): DmsExtraction
    {
        return DB::transaction(function () use ($extraction, $fields, $user): DmsExtraction {
            $locked = $this->openForDecision($extraction);
            $document = DmsDocument::query()->lockForUpdate()->findOrFail($locked->document_id);

            if ($document->legal_hold) {
                throw new ConflictHttpException('This document is under legal hold and cannot change.');
            }

            $locked->update(['status' => ExtractionStatus::Confirmed, 'confirmed_fields' => $fields, 'decided_by' => $user->id, 'decided_at' => now()]);
            $document->update(['metadata' => [...($document->metadata ?? []), 'extracted' => $fields]]);

            return $locked;
        });
    }

    public function reject(DmsExtraction $extraction, User $user): DmsExtraction
    {
        return DB::transaction(function () use ($extraction, $user): DmsExtraction {
            $locked = $this->openForDecision($extraction);
            $locked->update(['status' => ExtractionStatus::Rejected, 'decided_by' => $user->id, 'decided_at' => now()]);

            return $locked;
        });
    }

    /**
     * @throws ConflictHttpException
     */
    private function openForDecision(DmsExtraction $extraction): DmsExtraction
    {
        $locked = DmsExtraction::query()->lockForUpdate()->findOrFail($extraction->id);

        if ($locked->status !== ExtractionStatus::Proposed) {
            throw new ConflictHttpException('This extraction was already decided.');
        }

        return $locked;
    }
}
