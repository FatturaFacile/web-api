<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Services;

use App\Models\Tenant;
use App\Models\User;
use Fpdf\Fpdf;
use Illuminate\Http\UploadedFile;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsFolder;
use Modules\Documents\Services\DocumentLibrary;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;
use Modules\OcrExtraction\Domain\Exceptions\OcrExtractionException;
use Modules\OcrExtraction\Infrastructure\Models\DocumentExtractionJob;

/**
 * Stores a file that arrived outside the DMS (a mobile capture, an inbound e-mail attachment) in the `AI Inbox` folder of
 * the tenant. The DMS upload hook then queues the extraction with the channel given here. A repeated client reference
 * returns the job of the first delivery, so offline sync retries and mail redeliveries never extract twice.
 */
final readonly class InboxIntakeService
{
    public function __construct(
        private OcrTenantRunner $runner,
        private DocumentLibrary $library,
        private InboxUploadContext $context,
    ) {}

    /**
     * @throws OcrExtractionException when the tenant has no user to own the document
     */
    public function intake(string $tenantId, UploadedFile $file, SourceChannel $channel, ?DocumentCategory $hint, ?User $user, ?string $clientReference): DocumentExtractionJob
    {
        return $this->runner->run($tenantId, function (Tenant $tenant) use ($tenantId, $file, $channel, $hint, $user, $clientReference): DocumentExtractionJob {
            $marker = $clientReference !== null ? 'capture:'.mb_substr($clientReference, 0, 200) : null;

            if ($marker !== null) {
                $existing = DmsDocument::query()->where('tenant_id', $tenantId)->where('description', $marker)->first();
                $job = $existing !== null ? DocumentExtractionJob::query()->where('tenant_id', $tenantId)->where('dms_document_id', $existing->id)->orderByDesc('created_at')->first() : null;

                if ($job !== null) {
                    return $job;
                }
            }

            $owner = $user ?? $this->owner($tenant);
            $folder = DmsFolder::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => (string) config('ocr_extraction.inbox_folder_name')],
                ['access_roles' => [], 'created_by' => $owner->id],
            );

            $document = $this->context->run($channel, $hint, $user?->id, fn (): DmsDocument => $this->library->create([
                'folder_id' => $folder->id,
                'title' => $file->getClientOriginalName(),
                'description' => $marker,
                'document_type' => 'ai_inbox',
            ], $file, null, true, [], $owner));

            $job = DocumentExtractionJob::query()->where('tenant_id', $tenantId)->where('dms_document_id', $document->id)->orderByDesc('created_at')->first();

            if ($job === null) {
                throw new OcrExtractionException('The document was stored but could not be queued for extraction.');
            }

            return $job;
        });
    }

    /**
     * Joins the photos of a multi-page receipt or delivery note into one PDF, each page the size of its photo.
     *
     * @param  list<UploadedFile>  $pages  JPEG or PNG photos, in page order
     */
    public function pdfFromPages(array $pages, string $name): UploadedFile
    {
        $pdf = new Fpdf;
        $pdf->SetAutoPageBreak(false);

        foreach ($pages as $page) {
            $size = @getimagesize((string) $page->getRealPath());
            $type = $size[2] ?? null;

            if ($size === false || ! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                throw new OcrExtractionException('Batched pages must be JPEG or PNG photos.');
            }

            // Photos are taken at about 150 dpi after the deskew step of the app.
            $width = $size[0] / 150 * 25.4;
            $height = $size[1] / 150 * 25.4;
            $pdf->AddPage($width > $height ? 'L' : 'P', [$width, $height]);
            $pdf->Image((string) $page->getRealPath(), 0, 0, $width, $height, $type === IMAGETYPE_PNG ? 'PNG' : 'JPG');
        }

        $path = tempnam(sys_get_temp_dir(), 'batch');

        if ($path === false) {
            throw new OcrExtractionException('A temporary file could not be created.');
        }

        file_put_contents($path, $pdf->Output('S'));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function owner(Tenant $tenant): User
    {
        $owner = $tenant->users()->wherePivotIn('role', ['owner', 'admin'])->orderBy('users.created_at')->first();

        if (! $owner instanceof User) {
            throw new OcrExtractionException('The tenant has no owner to store the document under.');
        }

        return $owner;
    }
}
