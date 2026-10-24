<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\System;

use App\Exceptions\InvalidBackupArchiveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\RestoreUploadRequest;
use App\Http\Resources\TenantBackupResource;
use App\Http\Responses\ApiResponse;
use App\Jobs\GenerateTenantBackupJob;
use App\Models\TenantBackup;
use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manual backups of the data and files of the current tenant. Every query is scoped to the tenant, so the backups of
 * other tenants are not found.
 */
class TenantBackupController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantBackupService $backups,
        private readonly TenantRestoreService $restores,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            TenantBackupResource::collection(TenantBackup::query()->latest()->orderByDesc('id')->get()),
            __('backup.messages.listed')
        );
    }

    /**
     * Queue a new backup; it is returned as `pending` and built in the background.
     */
    public function store(Request $request): JsonResponse
    {
        $backup = $this->backups->create($this->tenantContext->getTenant(), $request->user());

        GenerateTenantBackupJob::dispatch($backup->id);

        return $this->successResponse(new TenantBackupResource($backup), __('backup.messages.queued'), Response::HTTP_ACCEPTED);
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new TenantBackupResource(TenantBackup::findOrFail($id)), __('backup.messages.retrieved'));
    }

    /**
     * Download the ZIP of a completed backup.
     */
    public function download(string $id): StreamedResponse|JsonResponse
    {
        $backup = TenantBackup::findOrFail($id);

        if (! $backup->isCompleted()) {
            return $this->problemDetailsResponse(__('backup.problem_titles.download'), __('backup.errors.not_ready'), Response::HTTP_CONFLICT);
        }

        if (! Storage::disk($backup->disk)->exists($backup->storagePath())) {
            return $this->problemDetailsResponse(__('backup.problem_titles.download'), __('backup.errors.file_missing'), Response::HTTP_NOT_FOUND);
        }

        return Storage::disk($backup->disk)->download($backup->storagePath(), 'backup-'.$backup->completed_at->format('Ymd-His').'.zip');
    }

    /**
     * Restore the tenant from one of its completed backups: the current data and files are replaced.
     */
    public function restore(string $id): JsonResponse
    {
        $backup = TenantBackup::findOrFail($id);

        if (! $backup->isCompleted()) {
            return $this->problemDetailsResponse(__('backup.problem_titles.restore'), __('backup.errors.not_restorable'), Response::HTTP_CONFLICT);
        }

        try {
            $summary = $this->restores->restoreBackup($backup, $this->tenantContext->getTenant());
        } catch (InvalidBackupArchiveException $e) {
            return $this->problemDetailsResponse(__('backup.problem_titles.restore'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse($summary, __('backup.messages.restored'));
    }

    /**
     * Restore the tenant from an uploaded ZIP archive; it is validated first and must belong to the current tenant.
     */
    public function restoreUpload(RestoreUploadRequest $request): JsonResponse
    {
        try {
            $summary = $this->restores->restoreArchive((string) $request->file('file')->getRealPath(), $this->tenantContext->getTenant());
        } catch (InvalidBackupArchiveException $e) {
            return $this->problemDetailsResponse(__('backup.problem_titles.restore'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse($summary, __('backup.messages.restored'));
    }

    /**
     * Delete the archive and its record.
     */
    public function destroy(string $id): JsonResponse
    {
        $backup = TenantBackup::findOrFail($id);

        if ($backup->status === TenantBackup::PROCESSING) {
            return $this->problemDetailsResponse(__('backup.problem_titles.delete'), __('backup.errors.in_progress'), Response::HTTP_CONFLICT);
        }

        $this->backups->delete($backup);

        return $this->successResponse(null, __('backup.messages.deleted'));
    }
}
