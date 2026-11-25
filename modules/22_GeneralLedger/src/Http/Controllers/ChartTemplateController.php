<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\ProvisionChartTemplateCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\ProvisionChartTemplateHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ListChartTemplatesHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\ListChartTemplatesQuery;
use Modules\GeneralLedger\Http\Requests\ProvisionChartTemplateRequest;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartTemplates\ChartTemplateCatalog;
use OpenApi\Attributes as OA;

final class ChartTemplateController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListChartTemplatesHandler $listTemplates,
        private readonly ProvisionChartTemplateHandler $provisionTemplate,
    ) {}

    /**
     * Lists the standard charts of accounts that can be loaded into the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/account-templates',
        operationId: 'listGeneralLedgerAccountTemplates',
        description: 'Lists the standard charts of accounts (Swiss KMU/Käfer, Italian Civil Code/OIC, French PCG, Spanish PGC, German SKR03/SKR04) with localized metadata and UI copy.',
        summary: 'List chart of accounts templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        responses: [
            new OA\Response(response: 200, description: 'Available templates', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'key', type: 'string', example: 'ch_kmu_pme'),
                    new OA\Property(property: 'standard', type: 'string', example: 'ch_kmu_pme'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'subtitle', type: 'string'),
                    new OA\Property(property: 'statutory_reference', type: 'string'),
                    new OA\Property(property: 'country', type: 'string', example: 'CH'),
                    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
                    new OA\Property(property: 'accounts', type: 'integer'),
                    new OA\Property(property: 'account_count', type: 'integer'),
                    new OA\Property(property: 'default_mappings_count', type: 'integer'),
                ])),
                new OA\Property(property: 'ui_copy', type: 'object'),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ChartOfAccount::class);

        $result = $this->listTemplates->handle(new ListChartTemplatesQuery(app()->getLocale()));

        $data = array_map(static function (array $item): array {
            $item['accounts'] = $item['account_count'];

            return $item;
        }, $result['data']);

        return response()->json([
            'data' => $data,
            'ui_copy' => $result['ui_copy'],
        ]);
    }

    /**
     * Loads a standard chart of accounts into the active tenant; accounts whose code already exists are skipped.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/account-templates/{template}/import',
        operationId: 'importGeneralLedgerAccountTemplate',
        description: '1-click idempotent provisioning of a standard chart of accounts (and optional default cross-standard mappings) into the active tenant.',
        summary: 'Import a chart of accounts template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'template', in: 'path', required: true, description: 'Template key: ch_kmu_pme, it_general_ledger, fr_pcg, es_pgc, de_skr03 or de_skr04', schema: new OA\Schema(type: 'string', enum: ['ch_kmu_pme', 'it_general_ledger', 'fr_pcg', 'es_pgc', 'de_skr03', 'de_skr04'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Import result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'template', type: 'string'),
                new OA\Property(property: 'created', type: 'integer'),
                new OA\Property(property: 'skipped', type: 'integer'),
                new OA\Property(property: 'mappings_created', type: 'integer'),
            ])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function import(ProvisionChartTemplateRequest $request, string $template): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);

        abort_unless(array_key_exists($template, ChartTemplateCatalog::all()), 404);

        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        abort_unless($tenantId !== null && $tenantId !== '', 403, 'Missing or unresolvable tenant context');

        $validated = $request->validated();
        $companyId = $request->header('X-Company-ID');
        $result = $this->provisionTemplate->handle(new ProvisionChartTemplateCommand(
            tenantId: (string) $tenantId,
            companyId: is_string($companyId) && $companyId !== '' ? $companyId : null,
            templateKey: $template,
            includeDefaultMappings: (bool) ($validated['include_default_mappings'] ?? true),
            locale: (string) ($validated['locale'] ?? app()->getLocale()),
        ));

        return response()->json([
            'data' => [
                'template' => $result['template'],
                'created' => $result['created_count'],
                'skipped' => $result['skipped_count'],
                'mappings_created' => $result['mappings_created'],
            ],
        ]);
    }
}
