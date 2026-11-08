<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Http\Responses\StoredFileHeaders;
use Illuminate\Support\Facades\Storage;
use Modules\Childcare\Models\ChildDocument;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the stored file of a child document to staff and guardians alike. The file is always sent as an attachment
 * with a safe name, its type from the central safe-headers helper and sniffing disabled, so an uploaded file can never
 * run in the application origin.
 */
final class ChildDocumentDownload
{
    /**
     * Streams the file of a document, or answers 404 when the document is only requested or the file is gone.
     */
    public function respond(ChildDocument $document): StreamedResponse
    {
        $disk = Storage::disk((string) config('childcare.documents_disk'));
        abort_if($document->file_path === null || ! $disk->exists($document->file_path), Response::HTTP_NOT_FOUND);

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $document->title).'.'.pathinfo($document->file_path, PATHINFO_EXTENSION);
        $headers = StoredFileHeaders::for($document->mime_type, $name, false);

        return $disk->download($document->file_path, $name, ['Content-Type' => $headers['Content-Type'], 'X-Content-Type-Options' => $headers['X-Content-Type-Options']]);
    }
}
