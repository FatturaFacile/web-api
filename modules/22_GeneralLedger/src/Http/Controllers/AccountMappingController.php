<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\DeleteAccountMappingCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\DeleteAccountMappingHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\SaveAccountMappingCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\SaveAccountMappingHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\SeedDefaultMappingsCommand;
use Modules\GeneralLedger\Application\ChartOfAccounts\Commands\SeedDefaultMappingsHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountMappingsHandler;
use Modules\GeneralLedger\Application\ChartOfAccounts\Queries\GetAccountMappingsQuery;
use Modules\GeneralLedger\Http\Requests\StoreAccountMappingRequest;
use Modules\GeneralLedger\Http\Resources\AccountMappingResource;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;
use OpenApi\Attributes as OA;

final class AccountMappingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GetAccountMappingsHandler $getMappings,
        private readonly SaveAccountMappingHandler $saveMapping,
        private readonly DeleteAccountMappingHandler $deleteMapping,
        private readonly SeedDefaultMappingsHandler $seedDefaultsHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/account-mappings',
        operationId: 'listGeneralLedgerAccountMappings',
        description: 'Lists multi-standard account mappings, bidirectional incoming mappings, and the grouped mapping tree.',
        summary: 'List account mappings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'source_account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'source_standard', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'target_standard', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mappings and tree', content: new OA\JsonContent(type: 'object', properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerAccountMappingResource')),
                new OA\Property(property: 'incoming', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerAccountMappingResource')),
                new OA\Property(property: 'tree', type: 'array', items: new OA\Items(type: 'object')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChartOfAccount::class);
        $tenantId = $this->resolveTenantId($request);

        $result = $this->getMappings->handle(new GetAccountMappingsQuery(
            tenantId: $tenantId,
            sourceAccountId: $request->query('source_account_id') !== null ? (string) $request->query('source_account_id') : null,
            sourceStandard: $request->query('source_standard') !== null ? (string) $request->query('source_standard') : null,
            targetStandard: $request->query('target_standard') !== null ? (string) $request->query('target_standard') : null,
            isActive: $request->query('active') !== null ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
        ));

        return response()->json([
            'data' => AccountMappingResource::collection($result['mappings']),
            'incoming' => AccountMappingResource::collection($result['incoming']),
            'tree' => $result['tree'],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/account-mappings',
        operationId: 'createGeneralLedgerAccountMapping',
        description: 'Creates a cross-standard account mapping.',
        summary: 'Create account mapping',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreAccountMappingRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Mapping created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccountMappingResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreAccountMappingRequest $request): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);
        $tenantId = $this->resolveTenantId($request);

        $companyId = $request->header('X-Company-ID');
        $mapping = $this->saveMapping->handle(new SaveAccountMappingCommand(
            tenantId: $tenantId,
            companyId: is_string($companyId) && $companyId !== '' ? $companyId : null,
            attributes: $request->validated(),
        ));

        return response()->json([
            'message' => 'Account mapping created successfully',
            'data' => new AccountMappingResource($mapping),
        ], 201);
    }

    #[OA\Put(
        path: '/api/v1/general-ledger/account-mappings/{mapping}',
        operationId: 'updateGeneralLedgerAccountMapping',
        description: 'Updates a cross-standard account mapping.',
        summary: 'Update account mapping',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'mapping', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreAccountMappingRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Mapping updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerAccountMappingResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreAccountMappingRequest $request, string $mapping): JsonResponse
    {
        $record = AccountMapping::findOrFail($mapping);
        $this->authorize('update', $record);

        $companyId = $request->header('X-Company-ID');
        $updated = $this->saveMapping->handle(new SaveAccountMappingCommand(
            tenantId: $this->resolveTenantId($request),
            companyId: is_string($companyId) && $companyId !== '' ? $companyId : null,
            attributes: $request->validated(),
            mappingId: $mapping,
        ));

        return response()->json([
            'message' => 'Account mapping updated successfully',
            'data' => new AccountMappingResource($updated),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/account-mappings/{mapping}',
        operationId: 'deleteGeneralLedgerAccountMapping',
        description: 'Deletes a cross-standard account mapping.',
        summary: 'Delete account mapping',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        parameters: [
            new OA\Parameter(name: 'mapping', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mapping deleted'),
        ]
    )]
    public function destroy(Request $request, string $mapping): JsonResponse
    {
        $record = AccountMapping::findOrFail($mapping);
        $this->authorize('delete', $record);

        $this->deleteMapping->handle(new DeleteAccountMappingCommand(
            tenantId: $this->resolveTenantId($request),
            mappingId: $mapping,
        ));

        return response()->json([
            'message' => 'Account mapping deleted successfully',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/account-mappings/seed-defaults',
        operationId: 'seedDefaultGeneralLedgerAccountMappings',
        description: 'Idempotently creates default KMU <-> OIC cross-standard account mappings for the active tenant.',
        summary: 'Seed default cross-standard mappings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Accounts'],
        responses: [
            new OA\Response(response: 200, description: 'Default mappings seeded'),
        ]
    )]
    public function seedDefaults(Request $request): JsonResponse
    {
        $this->authorize('create', ChartOfAccount::class);
        $tenantId = $this->resolveTenantId($request);

        $companyId = $request->header('X-Company-ID');
        $created = $this->seedDefaultsHandler->handle(new SeedDefaultMappingsCommand(
            tenantId: $tenantId,
            companyId: is_string($companyId) && $companyId !== '' ? $companyId : null,
            sourceStandard: $request->input('source_standard') !== null ? (string) $request->input('source_standard') : null,
        ));

        return response()->json([
            'message' => 'Default account mappings seeded successfully',
            'data' => ['created' => $created],
        ]);
    }

    private function resolveTenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }
}
