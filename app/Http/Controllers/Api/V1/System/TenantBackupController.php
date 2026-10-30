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
use OpenApi\Attributes as OA;
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

    #[OA\Get(
        path: '/api/v1/system/backups',
        operationId: 'getTenantBackups',
        description: 'List historical and active backups of the current tenant.',
        summary: 'List tenant backups',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        responses: [
            new OA\Response(response: 200, description: 'Backups listed successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
    #[OA\Post(
        path: '/api/v1/system/backups',
        operationId: 'createTenantBackup',
        description: 'Queue a new tenant backup job in the background.',
        summary: 'Create tenant backup',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        responses: [
            new OA\Response(response: 202, description: 'Backup job queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $backup = $this->backups->create($this->tenantContext->getTenant(), $request->user());

        GenerateTenantBackupJob::dispatch($backup->id);

        return $this->successResponse(new TenantBackupResource($backup), __('backup.messages.queued'), Response::HTTP_ACCEPTED);
    }

    #[OA\Get(
        path: '/api/v1/system/backups/{id}',
        operationId: 'getTenantBackupById',
        description: 'Get status and details of a specific backup archive.',
        summary: 'Get backup details',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Backup UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Backup not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new TenantBackupResource($this->findBackup($id)), __('backup.messages.retrieved'));
    }

    /**
     * Download the ZIP of a completed backup.
     */
    #[OA\Get(
        path: '/api/v1/system/backups/{id}/download',
        operationId: 'downloadTenantBackup',
        description: 'Download the ZIP archive of a completed backup.',
        summary: 'Download backup ZIP',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Backup UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup ZIP stream', content: new OA\MediaType(mediaType: 'application/zip')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Backup file missing or not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Backup not completed yet', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(string $id): StreamedResponse|JsonResponse
    {
        $backup = $this->findBackup($id);

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
    #[OA\Post(
        path: '/api/v1/system/backups/{id}/restore',
        operationId: 'restoreTenantBackup',
        description: 'Restore tenant database and storage from a stored completed backup archive.',
        summary: 'Restore from stored backup',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Backup UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tenant restored successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Backup not completed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid backup archive', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function restore(string $id): JsonResponse
    {
        $backup = $this->findBackup($id);

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
    #[OA\Post(
        path: '/api/v1/system/backups/restore-upload',
        operationId: 'restoreTenantBackupUpload',
        description: 'Restore tenant from an uploaded ZIP backup archive file.',
        summary: 'Restore from uploaded ZIP',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Backup ZIP archive file'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tenant restored successfully from upload', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid archive', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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
    #[OA\Delete(
        path: '/api/v1/system/backups/{id}',
        operationId: 'deleteTenantBackup',
        description: 'Delete a backup record and its archive file from disk.',
        summary: 'Delete tenant backup',
        security: [['bearerAuth' => []]],
        tags: ['System', 'Backups'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Backup UUID or ID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup deleted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Backup not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Cannot delete backup currently processing', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $backup = $this->findBackup($id);

        if ($backup->status === TenantBackup::PROCESSING) {
            return $this->problemDetailsResponse(__('backup.problem_titles.delete'), __('backup.errors.in_progress'), Response::HTTP_CONFLICT);
        }

        $this->backups->delete($backup);

        return $this->successResponse(null, __('backup.messages.deleted'));
    }

    private function findBackup(string $id): TenantBackup
    {
        return TenantBackup::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }
}
