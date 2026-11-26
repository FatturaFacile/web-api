<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DataMigration\Application\Commands\SaveMigrationFieldMappingTemplateCommand;
use Modules\DataMigration\Application\Commands\SaveMigrationFieldMappingTemplateHandler;
use Modules\DataMigration\Application\Queries\DownloadExcelMigrationTemplatesHandler;
use Modules\DataMigration\Application\Queries\DownloadExcelMigrationTemplatesQuery;
use Modules\DataMigration\Application\Queries\ListMigrationTemplatesHandler;
use Modules\DataMigration\Application\Queries\ListMigrationTemplatesQuery;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Http\Requests\DownloadTemplateRequest;
use Modules\DataMigration\Http\Requests\StoreMigrationTemplateRequest;
use Modules\DataMigration\Http\Resources\MigrationTemplateResource;
use Modules\DataMigration\Policies\DataMigrationPolicy;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MigrationTemplateController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(
        path: '/api/v1/data-migration/templates',
        operationId: 'dataMigrationTemplatesIndex',
        summary: 'List system preset and tenant-custom field mapping templates',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Templates list')]
    )]
    public function index(Request $request, ListMigrationTemplatesHandler $handler): JsonResponse
    {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $templates = $handler->handle(new ListMigrationTemplatesQuery(
            sourceSystem: $request->query('source_system') ? (string) $request->query('source_system') : null,
            entityType: $request->query('entity_type') ? (string) $request->query('entity_type') : null,
        ));

        return $this->successResponse(MigrationTemplateResource::collection($templates));
    }

    #[OA\Post(
        path: '/api/v1/data-migration/templates',
        operationId: 'dataMigrationTemplatesStore',
        summary: 'Save a reusable column mapping and transformation template',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 201, description: 'Template created')]
    )]
    public function store(
        StoreMigrationTemplateRequest $request,
        TenantContext $tenantContext,
        SaveMigrationFieldMappingTemplateHandler $handler,
    ): JsonResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $validated = $request->validated();
        $template = $handler->handle(new SaveMigrationFieldMappingTemplateCommand(
            tenantId: (string) $tenantContext->getTenantId(),
            name: (string) $validated['name'],
            sourceSystem: MigrationSourceSystem::from((string) $validated['source_system']),
            entityType: MigrationTargetEntity::from((string) $validated['entity_type']),
            columnMappings: (array) $validated['column_mappings'],
            valueTransformations: isset($validated['value_transformations']) ? (array) $validated['value_transformations'] : null,
        ));

        return $this->createdResponse(new MigrationTemplateResource($template), __('data_migration.template_saved'));
    }

    #[OA\Get(
        path: '/api/v1/data-migration/templates/download',
        operationId: 'dataMigrationTemplatesDownload',
        summary: 'Download .xlsx or .csv migration template for a target entity',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [new OA\Response(response: 200, description: 'Template file')]
    )]
    public function download(
        DownloadTemplateRequest $request,
        DownloadExcelMigrationTemplatesHandler $handler,
    ): BinaryFileResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $validated = $request->validated();
        $file = $handler->handle(new DownloadExcelMigrationTemplatesQuery(
            entityType: MigrationTargetEntity::from((string) $validated['entity_type']),
            format: (string) ($validated['format'] ?? 'xlsx'),
            locale: (string) ($validated['locale'] ?? app()->getLocale()),
        ));

        return response()->download($file['path'], $file['filename'])->deleteFileAfterSend(true);
    }
}
