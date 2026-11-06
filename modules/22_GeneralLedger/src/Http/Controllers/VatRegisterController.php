<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Http\Requests\VatRegisterRequest;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\VatFilingExportService;
use Modules\GeneralLedger\Services\VatRegisterService;
use OpenApi\Attributes as OA;

final class VatRegisterController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VatRegisterService $registerService,
        private readonly VatFilingExportService $exportService
    ) {}

    /**
     * Returns the sales or purchases VAT register of a date range.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/vat-registers/{type}',
        operationId: 'getGeneralLedgerVatRegister',
        description: 'Returns the sales or purchases VAT register of the active tenant, read from the posted journal entries. Protocol numbers are assigned when the settlement of the period is posted.',
        summary: 'Get VAT register',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, description: 'Register', schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases'])),
            new OA\Parameter(name: 'from', in: 'query', required: true, description: 'First day', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Last day', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Output format', schema: new OA\Schema(type: 'string', default: 'json', enum: ['json', 'csv'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Register lines, or the CSV file with `format=csv`',
                content: [
                    new OA\JsonContent(type: 'object', properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerVatRegisterLine')),
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'taxable', type: 'string'),
                            new OA\Property(property: 'tax', type: 'string'),
                        ]),
                    ]),
                    new OA\MediaType(mediaType: 'text/csv'),
                ]
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(VatRegisterRequest $request, string $type): JsonResponse|Response
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        $tenantId = $this->tenantContext->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        $from = CarbonImmutable::parse((string) $request->validated('from'));
        $to = CarbonImmutable::parse((string) $request->validated('to'));
        $register = $this->registerService->linesOf($tenantId, $type, $from, $to);

        if ($request->validated('format') === 'csv') {
            return response($this->exportService->registerCsv($register), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"vat-register-{$type}-{$from->toDateString()}-{$to->toDateString()}.csv\"",
            ]);
        }

        return response()->json(['data' => $register['lines'], 'totals' => $register['totals']]);
    }
}
