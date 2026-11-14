<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartTemplates\ChartTemplateCatalog;
use Modules\GeneralLedger\Services\ChartTemplates\ChartTemplateImporter;
use OpenApi\Attributes as OA;

final class ChartTemplateController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ChartTemplateImporter $importer,
    ) {}

    /**
     * Lists the standard charts of accounts that can be loaded into the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/account-templates',
        operationId: 'listGeneralLedgerAccountTemplates',
        description: 'Lists the standard charts of accounts (Swiss SME chart, Italian chart) with their standard, country, currency and number of accounts.',
        summary: 'List chart of accounts templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        responses: [
            new OA\Response(response: 200, description: 'Available templates', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'key', type: 'string', example: 'ch_kmu_pme'),
                new OA\Property(property: 'standard', type: 'string', example: 'ch_kmu_pme'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'country', type: 'string', example: 'CH'),
                new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
                new OA\Property(property: 'accounts', type: 'integer'),
            ]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ChartOfAccount::class);

        $templates = [];

        foreach ($this->importer->templates() as $key => $template) {
            $templates[] = ['key' => $key, ...$template];
        }

        return response()->json(['data' => $templates]);
    }

    /**
     * Loads a standard chart of accounts into the active tenant; accounts whose code already exists are skipped.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/account-templates/{template}/import',
        operationId: 'importGeneralLedgerAccountTemplate',
        description: 'Loads a standard chart of accounts into the active tenant. The import is idempotent: accounts whose code already exists are left untouched and counted as skipped.',
        summary: 'Import a chart of accounts template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'template', in: 'path', required: true, description: 'Template key: ch_kmu_pme or it_general_ledger', schema: new OA\Schema(type: 'string', enum: ['ch_kmu_pme', 'it_general_ledger'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Import result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'template', type: 'string'),
                new OA\Property(property: 'created', type: 'integer'),
                new OA\Property(property: 'skipped', type: 'integer'),
            ])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function import(Request $request, string $template): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);

        abort_unless(array_key_exists($template, ChartTemplateCatalog::all()), 404);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        abort_unless($tenantId, 403, 'Missing or unresolvable tenant context');

        return response()->json(['data' => $this->importer->import((string) $tenantId, $template)]);
    }
}
