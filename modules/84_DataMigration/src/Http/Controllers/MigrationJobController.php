<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DataMigration\Application\Commands\AutoDetectColumnMappingCommand;
use Modules\DataMigration\Application\Commands\AutoDetectColumnMappingHandler;
use Modules\DataMigration\Application\Commands\CommitMigrationJobCommand;
use Modules\DataMigration\Application\Commands\CommitMigrationJobHandler;
use Modules\DataMigration\Application\Commands\PatchMigrationStagingRowCommand;
use Modules\DataMigration\Application\Commands\PatchMigrationStagingRowHandler;
use Modules\DataMigration\Application\Commands\RollbackMigrationBatchCommand;
use Modules\DataMigration\Application\Commands\RollbackMigrationBatchHandler;
use Modules\DataMigration\Application\Commands\RunMigrationDryRunSimulationCommand;
use Modules\DataMigration\Application\Commands\RunMigrationDryRunSimulationHandler;
use Modules\DataMigration\Application\Commands\UploadMigrationSourceArchiveCommand;
use Modules\DataMigration\Application\Commands\UploadMigrationSourceArchiveHandler;
use Modules\DataMigration\Application\Queries\GetMigrationDryRunReportHandler;
use Modules\DataMigration\Application\Queries\GetMigrationDryRunReportQuery;
use Modules\DataMigration\Application\Queries\ListMigrationJobsHandler;
use Modules\DataMigration\Application\Queries\ListMigrationJobsQuery;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Http\Requests\AutoDetectMappingRequest;
use Modules\DataMigration\Http\Requests\PatchStagingRowRequest;
use Modules\DataMigration\Http\Requests\UploadMigrationArchiveRequest;
use Modules\DataMigration\Http\Resources\MigrationJobResource;
use Modules\DataMigration\Http\Resources\MigrationStagingRowResource;
use Modules\DataMigration\Http\Resources\MigrationValidationErrorResource;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Policies\DataMigrationPolicy;
use OpenApi\Attributes as OA;

final class MigrationJobController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(
        path: '/api/v1/data-migration/jobs',
        operationId: 'dataMigrationJobsIndex',
        summary: 'List migration jobs for the active tenant',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Migration jobs list')]
    )]
    public function index(Request $request, ListMigrationJobsHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $jobs = $handler->handle(new ListMigrationJobsQuery(
            status: $request->query('status') ? (string) $request->query('status') : null,
            sourceSystem: $request->query('source_system') ? (string) $request->query('source_system') : null,
            legalEntityId: $request->query('legal_entity_id') ? (string) $request->query('legal_entity_id') : null,
        ));

        return $this->successResponse(MigrationJobResource::collection($jobs));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/jobs',
        operationId: 'dataMigrationJobsStore',
        summary: 'Upload source archive (.zip, .xlsx, .csv, .xml) and initialize a migration job',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 201, description: 'Migration job created')]
    )]
    public function store(
        UploadMigrationArchiveRequest $request,
        TenantContext $tenantContext,
        UploadMigrationSourceArchiveHandler $handler,
    ): JsonResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $validated = $request->validated();
        $file = $request->file('file');

        $job = $handler->handle(new UploadMigrationSourceArchiveCommand(
            tenantId: (string) $tenantContext->getTenantId(),
            legalEntityId: isset($validated['legal_entity_id']) ? (string) $validated['legal_entity_id'] : null,
            userId: $request->user()?->getKey() !== null ? (string) $request->user()?->getKey() : null,
            sourceSystem: MigrationSourceSystem::from((string) $validated['source_system']),
            targetEntities: array_values(array_map('strval', (array) $validated['target_entities'])),
            filePath: (string) $file?->getRealPath(),
            originalFileName: (string) $file?->getClientOriginalName(),
            extension: strtolower((string) $file?->getClientOriginalExtension()),
            templateId: isset($validated['template_id']) ? (string) $validated['template_id'] : null,
        ));

        return $this->createdResponse(new MigrationJobResource($job), __('data_migration.job_uploaded'));
    }

    #[OA\Get(
        path: '/api/v1/data-migration/jobs/{id}',
        operationId: 'dataMigrationJobsShow',
        summary: 'Retrieve a migration job detail',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Migration job detail')]
    )]
    public function show(string $id): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);
        $job = MigrationJob::query()->findOrFail($id);

        return $this->successResponse(new MigrationJobResource($job));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/jobs/{id}/auto-map',
        operationId: 'dataMigrationJobsAutoMap',
        summary: 'Auto-detect or save column mappings and value transformations for a migration job',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Mapping updated')]
    )]
    public function autoMap(
        string $id,
        AutoDetectMappingRequest $request,
        AutoDetectColumnMappingHandler $handler,
    ): JsonResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $validated = $request->validated();
        $job = $handler->handle(new AutoDetectColumnMappingCommand(
            jobId: $id,
            templateId: isset($validated['template_id']) ? (string) $validated['template_id'] : null,
            overrideMappingsByEntity: isset($validated['mappings_by_entity']) ? (array) $validated['mappings_by_entity'] : null,
            overrideTransformations: isset($validated['value_transformations']) ? (array) $validated['value_transformations'] : null,
        ));

        return $this->successResponse(new MigrationJobResource($job), __('data_migration.mapping_updated'));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/jobs/{id}/dry-run',
        operationId: 'dataMigrationJobsDryRun',
        summary: 'Execute mandatory dry-run simulation without committing business records',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Dry-run report')]
    )]
    public function dryRun(string $id, RunMigrationDryRunSimulationHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $result = $handler->handle(new RunMigrationDryRunSimulationCommand($id));

        return $this->successResponse([
            'job' => new MigrationJobResource($result['job']),
            'summary' => $result['summary'],
        ], __('data_migration.dry_run_completed'));
    }

    #[OA\Get(
        path: '/api/v1/data-migration/jobs/{id}/dry-run-report',
        operationId: 'dataMigrationJobsDryRunReport',
        summary: 'Retrieve dry-run validation errors and editable staging rows',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Dry-run detailed report')]
    )]
    public function dryRunReport(string $id, Request $request, GetMigrationDryRunReportHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $report = $handler->handle(new GetMigrationDryRunReportQuery(
            jobId: $id,
            entityType: $request->query('entity_type') ? (string) $request->query('entity_type') : null,
            unresolvedOnly: $request->boolean('unresolved_only'),
        ));

        return $this->successResponse([
            'job' => new MigrationJobResource($report['job']),
            'summary' => $report['summary'],
            'errors' => MigrationValidationErrorResource::collection($report['errors']),
            'staging_rows' => MigrationStagingRowResource::collection($report['staging_rows']),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/data-migration/jobs/{id}/staging-rows/{rowId}',
        operationId: 'dataMigrationJobsPatchStagingRow',
        summary: 'Patch staging row values inline before re-running dry-run',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Staging row patched')]
    )]
    public function patchStagingRow(
        string $id,
        string $rowId,
        PatchStagingRowRequest $request,
        PatchMigrationStagingRowHandler $handler,
    ): JsonResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $row = $handler->handle(new PatchMigrationStagingRowCommand(
            jobId: $id,
            rowId: $rowId,
            values: (array) $request->validated('values'),
        ));

        return $this->successResponse(new MigrationStagingRowResource($row), __('data_migration.row_patched'));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/jobs/{id}/commit',
        operationId: 'dataMigrationJobsCommit',
        summary: 'Atomically commit a DryRunValidated migration job into target ERP modules',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Migration committed')]
    )]
    public function commit(string $id, CommitMigrationJobHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $result = $handler->handle(new CommitMigrationJobCommand($id));

        return $this->successResponse([
            'job' => new MigrationJobResource($result['job']),
            'imported_rows' => $result['imported_rows'],
            'manifest_entries' => $result['manifest_entries'],
        ], __('data_migration.job_committed'));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/jobs/{id}/rollback',
        operationId: 'dataMigrationJobsRollback',
        summary: 'Atomically roll back all records created by a Completed migration job',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Migration rolled back')]
    )]
    public function rollback(string $id, RollbackMigrationBatchHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ROLLBACK);

        $result = $handler->handle(new RollbackMigrationBatchCommand($id));

        return $this->successResponse([
            'job' => new MigrationJobResource($result['job']),
            'reverted_records' => $result['reverted_records'],
        ], __('data_migration.job_rolled_back'));
    }
}
