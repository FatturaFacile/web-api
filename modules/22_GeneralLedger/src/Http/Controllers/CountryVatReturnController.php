<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Localization\CountryVatReturnService;
use Modules\GeneralLedger\Services\Localization\DatevExporter;
use OpenApi\Attributes as OA;

/**
 * Periodic VAT return data computed from the ledger for France (CA3), Spain (Modelo 303 and 390) and Germany (UStVA,
 * DATEV export of the bookings).
 */
final class CountryVatReturnController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CountryVatReturnService $returns,
    ) {}

    #[OA\Get(path: '/api/v1/general-ledger/fr/ca3', operationId: 'getGeneralLedgerFrenchCa3', description: 'Boxes of the French VAT return CA3 for a period, computed from the posted ledger (sales and purchases VAT registers). Optional previous_credit: credit carried over (line 22).', summary: 'French VAT return CA3', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'previous_credit', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0)),
    ], responses: [
        new OA\Response(response: 200, description: 'Return boxes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CountryVatReturn')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function ca3(Request $request): JsonResponse
    {
        [$from, $to, $credit] = $this->period($request, true);

        return response()->json(['data' => $this->returns->ca3($this->tenantId(), $from, $to, $credit)]);
    }

    #[OA\Get(path: '/api/v1/general-ledger/fr/ca3/export', operationId: 'exportGeneralLedgerFrenchCa3', description: 'CSV (code, label, amount) of the boxes of the CA3 for a period.', summary: 'Export the CA3', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'previous_credit', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0)),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function ca3Export(Request $request): Response
    {
        [$from, $to, $credit] = $this->period($request, true);

        return $this->csv($this->returns->ca3($this->tenantId(), $from, $to, $credit), $from, $to);
    }

    #[OA\Get(path: '/api/v1/general-ledger/es/modelo-303', operationId: 'getGeneralLedgerSpanishModelo303', description: 'Boxes of the Spanish quarterly VAT return Modelo 303 for a period, computed from the posted ledger. Optional previous_credit: VAT to offset from previous periods (box 67).', summary: 'Spanish VAT return Modelo 303', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'previous_credit', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0)),
    ], responses: [
        new OA\Response(response: 200, description: 'Return boxes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CountryVatReturn')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function modelo303(Request $request): JsonResponse
    {
        [$from, $to, $credit] = $this->period($request, true);

        return response()->json(['data' => $this->returns->modelo303($this->tenantId(), $from, $to, $credit)]);
    }

    #[OA\Get(path: '/api/v1/general-ledger/es/modelo-303/export', operationId: 'exportGeneralLedgerSpanishModelo303', description: 'CSV (code, label, amount) of the boxes of the Modelo 303 for a period.', summary: 'Export the Modelo 303', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'previous_credit', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0)),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function modelo303Export(Request $request): Response
    {
        [$from, $to, $credit] = $this->period($request, true);

        return $this->csv($this->returns->modelo303($this->tenantId(), $from, $to, $credit), $from, $to);
    }

    #[OA\Get(path: '/api/v1/general-ledger/es/modelo-390', operationId: 'getGeneralLedgerSpanishModelo390', description: 'Annual VAT summary Modelo 390: the four quarterly 303 returns of the year and the whole year as one period.', summary: 'Spanish annual VAT summary Modelo 390', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 2000, maximum: 2100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Annual summary', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
            new OA\Property(property: 'form', type: 'string', example: '390'),
            new OA\Property(property: 'country', type: 'string', example: 'ES'),
            new OA\Property(property: 'year', type: 'integer'),
            new OA\Property(property: 'quarters', type: 'array', items: new OA\Items(ref: '#/components/schemas/CountryVatReturn')),
            new OA\Property(property: 'annual', ref: '#/components/schemas/CountryVatReturn'),
        ])])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function modelo390(Request $request): JsonResponse
    {
        $this->authorize('viewReturns', VatSettlement::class);
        $validated = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return response()->json(['data' => $this->returns->modelo390($this->tenantId(), (int) $validated['year'])]);
    }

    #[OA\Get(path: '/api/v1/general-ledger/de/ustva', operationId: 'getGeneralLedgerGermanUstva', description: 'Boxes (Kennzahlen) of the German advance VAT return UStVA for a period, computed from the posted ledger.', summary: 'German VAT return UStVA', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Return boxes', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CountryVatReturn')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function ustva(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request, false);

        return response()->json(['data' => $this->returns->ustva($this->tenantId(), $from, $to)]);
    }

    #[OA\Get(path: '/api/v1/general-ledger/de/ustva/export', operationId: 'exportGeneralLedgerGermanUstva', description: 'CSV (code, label, amount) of the Kennzahlen of the UStVA for a period.', summary: 'Export the UStVA', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Tax Settlements'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function ustvaExport(Request $request): Response
    {
        [$from, $to] = $this->period($request, false);

        return $this->csv($this->returns->ustva($this->tenantId(), $from, $to), $from, $to);
    }

    #[OA\Get(path: '/api/v1/general-ledger/de/datev/export', operationId: 'exportGeneralLedgerDatev', description: 'DATEV Buchungsstapel (EXTF 700, category 21, version 13) of the posted journal entries of a period: header line, column headings, one row per booking between one debit and one credit account (the first 14 columns of the format). Semicolon separated, Windows-1252. Accounts must be numeric.', summary: 'Export the DATEV Buchungsstapel', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Journals'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'consultant_number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 9999999, default: 1)),
        new OA\Parameter(name: 'client_number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 99999, default: 1)),
        new OA\Parameter(name: 'account_length', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 4, maximum: 8, default: 4)),
    ], responses: [
        new OA\Response(response: 200, description: 'DATEV CSV file', content: new OA\MediaType(mediaType: 'text/csv')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or non-numeric accounts', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function datev(Request $request, DatevExporter $exporter): Response
    {
        $this->authorize('viewReturns', VatSettlement::class);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'consultant_number' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'client_number' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'account_length' => ['nullable', 'integer', 'min:4', 'max:8'],
        ]);
        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);
        $file = $exporter->build(
            $this->tenantId(),
            $from,
            $to,
            (int) ($validated['consultant_number'] ?? 1),
            (int) ($validated['client_number'] ?? 1),
            (int) ($validated['account_length'] ?? 4),
        );
        $fileName = $exporter->fileName($from, $to);

        return response($file['content'], 200, [
            'Content-Type' => 'text/csv; charset=Windows-1252',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * @param  array{form: string, boxes: list<array{code: string, label: string, amount: string}>}  $return
     */
    private function csv(array $return, CarbonImmutable $from, CarbonImmutable $to): Response
    {
        $fileName = $this->returns->fileName($return['form'], $from, $to);

        return response($this->returns->csv($return), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(Request $request, bool $withCredit): array
    {
        $this->authorize('viewReturns', VatSettlement::class);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'previous_credit' => $withCredit ? ['nullable', 'numeric', 'min:0'] : ['prohibited'],
        ]);

        return [CarbonImmutable::parse($validated['from']), CarbonImmutable::parse($validated['to']), (string) ($validated['previous_credit'] ?? '0')];
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
