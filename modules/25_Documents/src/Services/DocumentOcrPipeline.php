<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Enums\OcrStatus;
use Modules\Documents\Exceptions\OcrFailedException;
use Modules\Documents\Jobs\RunDocumentOcrJob;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsOcrJob;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * OCR pipeline of the DMS: a request stores a queued job for the current version, a queued worker hands the file to the
 * engine that supports its format and stores the text with the extraction status. The engine runs outside any
 * database transaction because it may call an external service.
 */
final readonly class DocumentOcrPipeline
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private OcrEngineRegistry $engines, private TenantContext $tenantContext) {}

    /**
     * Queues OCR for the current version of a document. A run already queued or processing is returned as it is.
     */
    public function queue(DmsDocument $document, User $user): DmsOcrJob
    {
        $version = $document->versions()->firstOrFail();

        $active = DmsOcrJob::query()
            ->where('document_version_id', $version->id)
            ->whereIn('status', [OcrStatus::Queued->value, OcrStatus::Processing->value])
            ->first();

        if ($active !== null) {
            return $active;
        }

        $job = DmsOcrJob::create([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'status' => OcrStatus::Queued,
            'requested_by' => $user->id,
        ]);

        RunDocumentOcrJob::dispatch((string) $job->id, (string) $document->tenant_id);

        return $job;
    }

    /**
     * Queues a failed run again.
     *
     * @throws ConflictHttpException when the run did not fail
     */
    public function retry(DmsOcrJob $job): DmsOcrJob
    {
        if ($job->status !== OcrStatus::Failed) {
            throw new ConflictHttpException('Only a failed OCR run can be retried.');
        }

        $job->update(['status' => OcrStatus::Queued, 'failure_reason' => null, 'finished_at' => null]);
        RunDocumentOcrJob::dispatch((string) $job->id, (string) $job->tenant_id);

        return $job;
    }

    /**
     * Runs a queued job. Used by the queued worker; a job that is not queued is left alone.
     */
    public function process(DmsOcrJob $job): DmsOcrJob
    {
        $claimed = DmsOcrJob::query()
            ->whereKey($job->id)
            ->where('status', OcrStatus::Queued->value)
            ->update(['status' => OcrStatus::Processing->value, 'started_at' => now(), 'attempts' => $job->attempts + 1]);

        if ($claimed === 0) {
            return $job->refresh();
        }

        $job->refresh();
        $version = $job->version()->first();

        try {
            if ($version === null) {
                throw new OcrFailedException('The version of the document no longer exists.');
            }

            $contents = Storage::disk($version->disk)->get($version->path);

            if ($contents === null) {
                throw new OcrFailedException('The file of the version is missing from storage.');
            }

            $mimeType = (string) ($version->mime_type ?? 'application/octet-stream');
            $engine = $this->engines->engineFor($mimeType);
            $result = $engine->recognize($contents, $mimeType);

            $job->update([
                'status' => OcrStatus::Completed,
                'engine' => $engine->name(),
                'text_content' => $result->text,
                'page_count' => $result->pageCount,
                'confidence' => $result->confidence,
                'failure_reason' => null,
                'finished_at' => now(),
            ]);
        } catch (OcrFailedException $exception) {
            $this->fail($job, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($job, 'The OCR engine failed unexpectedly.');
        }

        return $job;
    }

    /**
     * Runs a job for the tenant that owns it (queue entry point).
     */
    public function processFor(string $jobId, string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->tenantContext->runFor($tenant, function () use ($jobId): void {
            $job = DmsOcrJob::query()->find($jobId);

            if ($job !== null) {
                $this->process($job);
            }
        });
    }

    private function fail(DmsOcrJob $job, string $reason): void
    {
        $job->update(['status' => OcrStatus::Failed, 'failure_reason' => mb_substr($reason, 0, 500), 'finished_at' => now()]);
    }
}
