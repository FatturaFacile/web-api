<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\OcrExtraction\Application\Commands\EnqueueDocumentOcrExtractionCommand;
use Modules\OcrExtraction\Application\Handlers\EnqueueDocumentOcrExtractionHandler;
use Throwable;

/**
 * DMS upload hook (Mod 007): the first version of a document stored in the tenant's `AI Inbox` folder enters the
 * extraction queue. A failure here is reported and never fails the upload.
 */
final readonly class EnqueueOnInboxUpload
{
    public function __construct(private EnqueueDocumentOcrExtractionHandler $enqueue, private InboxUploadContext $context) {}

    public function handle(DmsDocumentVersion $version): void
    {
        if ($version->version_number !== 1 || $version->tenant_id === null) {
            return;
        }

        try {
            $inbox = DB::table('dms_documents')
                ->join('dms_folders', 'dms_folders.id', '=', 'dms_documents.folder_id')
                ->where('dms_documents.id', $version->document_id)
                ->where('dms_folders.tenant_id', $version->tenant_id)
                ->where('dms_folders.name', config('ocr_extraction.inbox_folder_name'))
                ->exists();

            if ($inbox) {
                $this->enqueue->handle(new EnqueueDocumentOcrExtractionCommand((string) $version->tenant_id, (string) $version->document_id, $this->context->channel(), $this->context->hint(), $this->context->requestedBy()));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
