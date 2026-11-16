<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Localization\JournalLetteringService;
use OpenApi\Attributes as OA;

/**
 * Lettering of ledger lines (French FEC columns EcritureLet and DateLet).
 */
final class JournalLetteringController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly JournalLetteringService $letterings,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/letterings',
        operationId: 'listGeneralLedgerLetterings',
        description: 'Letterings of the active tenant, one entry per code with the lines it groups. Filter: account_id.',
        summary: 'List letterings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Letterings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate(['account_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->letterings->list($this->tenantId(), $validated['account_id'] ?? null)]);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/letterings',
        operationId: 'createGeneralLedgerLettering',
        description: 'Letters posted ledger lines of one account and partner whose debits and credits cancel out. The next code (AA, AB, ...) is assigned.',
        summary: 'Letter ledger lines',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['journal_line_ids'], properties: [
            new OA\Property(property: 'journal_line_ids', type: 'array', minItems: 2, items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'lettered_on', type: 'string', format: 'date', nullable: true),
        ])),
        tags: ['General Ledger Journals'],
        responses: [
            new OA\Response(response: 201, description: 'Lettered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Lines of different accounts or partners, not posted, already lettered or not balanced', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        $validated = $request->validate([
            'journal_line_ids' => ['required', 'array', 'min:2', 'max:200'],
            'journal_line_ids.*' => ['uuid', 'distinct'],
            'lettered_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->letterings->letter(
            $this->tenantId(),
            $validated['journal_line_ids'],
            $validated['lettered_on'] ?? now()->toDateString(),
        )], 201);
    }

    #[OA\Delete(
        path: '/api/v1/general-ledger/letterings/{code}',
        operationId: 'deleteGeneralLedgerLettering',
        description: 'Removes a lettering: its lines become unlettered again.',
        summary: 'Remove a lettering',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[A-Z]{2,}$'))],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown lettering', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $code): Response
    {
        $this->authorize('manageSettings', VatSettlement::class);
        abort_if($this->letterings->unletter($this->tenantId(), $code) === 0, 404);

        return response()->noContent();
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
