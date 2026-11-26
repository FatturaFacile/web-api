<?php

declare(strict_types=1);

namespace Modules\DataMigration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\DataMigration\Application\Queries\ListMigrationTemplatesHandler;
use Modules\DataMigration\Application\Queries\ListMigrationTemplatesQuery;
use Modules\DataMigration\Http\Resources\MigrationTemplateResource;
use Modules\DataMigration\Policies\DataMigrationPolicy;
use Modules\DataMigration\Services\ConnectorPresetRegistry;
use OpenApi\Attributes as OA;

final class DataMigrationMetadataController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(
        path: '/api/v1/data-migration/metadata',
        operationId: 'dataMigrationMetadata',
        summary: 'Retrieve connectors, target entity schemas, and mapping templates for Guided Data Migration (Mod 104)',
        security: self::SECURITY,
        tags: ['Data Migration'],
        responses: [
            new OA\Response(response: 200, description: 'Migration metadata loaded'),
        ]
    )]
    public function index(
        Request $request,
        ConnectorPresetRegistry $registry,
        ListMigrationTemplatesHandler $templatesHandler,
    ): JsonResponse {
        Gate::authorize(DataMigrationPolicy::ABILITY_ACCESS);

        $templates = $templatesHandler->handle(new ListMigrationTemplatesQuery(
            sourceSystem: $request->query('source_system') ? (string) $request->query('source_system') : null,
            entityType: $request->query('entity_type') ? (string) $request->query('entity_type') : null,
        ));

        return $this->successResponse([
            'connectors' => $registry->connectorsMetadata(),
            'entities' => $registry->entitySchemas(),
            'templates' => MigrationTemplateResource::collection($templates),
            'supported_locales' => ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'],
        ], __('data_migration.metadata_loaded'));
    }
}
