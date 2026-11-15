<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Services\Localization\FecExporter;
use Modules\GeneralLedger\Services\Localization\SiiVatBookExporter;
use Modules\GeneralLedger\Services\Localization\VatCodePackCatalog;
use Modules\GeneralLedger\Services\Localization\VatCodePackImporter;
use Modules\GeneralLedger\Services\VatRegisterService;
use OpenApi\Attributes as OA;

/**
 * Localization packs of France and Spain: the VAT code packs, the FEC export (France) and the SII VAT books (Spain).
 * The charts of accounts (fr_pcg, es_pgc) are loaded with the account templates.
 */
final class LocalizationPackController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VatCodePackImporter $vatCodes,
    ) {}

    #[OA\Get(
        path: '/api/v1/general-ledger/vat-code-packs',
        operationId: 'listGeneralLedgerVatCodePacks',
        description: 'Lists the VAT code packs (France, Spain) that can be loaded.',
        summary: 'List VAT code packs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        responses: [
            new OA\Response(response: 200, description: 'Available packs', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'country', type: 'string', example: 'FR'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'codes', type: 'integer'),
            ]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function vatCodePacks(): JsonResponse
    {
        $this->authorize('viewRegisters', VatSettlement::class);

        return response()->json(['data' => $this->vatCodes->packs()]);
    }

    #[OA\Post(
        path: '/api/v1/general-ledger/vat-code-packs/{country}/import',
        operationId: 'importGeneralLedgerVatCodePack',
        description: 'Loads the VAT codes (rates, reverse charge, exemptions) of a country as shared codes. Idempotent: existing codes are updated, never duplicated.',
        summary: 'Import a VAT code pack',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [new OA\Parameter(name: 'country', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['FR', 'ES']))],
        responses: [
            new OA\Response(response: 200, description: 'Import result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'country', type: 'string'),
                new OA\Property(property: 'created', type: 'integer'),
                new OA\Property(property: 'updated', type: 'integer'),
            ])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown pack', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function importVatCodePack(string $country): JsonResponse
    {
        $this->authorize('manageSettings', VatSettlement::class);
        abort_unless(array_key_exists(strtoupper($country), VatCodePackCatalog::all()), 404);

        return response()->json(['data' => $this->vatCodes->import($country)]);
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/fec/export',
        operationId: 'exportGeneralLedgerFec',
        description: 'Fichier des Ecritures Comptables (FEC) of a fiscal year of the active tenant: the 18 columns required by the French tax authority, tab separated, dates as YYYYMMDD, decimal comma, one row per posted line. The file is named SirenFECYYYYMMDD.txt.',
        summary: 'Export the FEC',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Journals'],
        parameters: [new OA\Parameter(name: 'fiscal_year_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The FEC file', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Fiscal year not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function fec(Request $request, FecExporter $exporter): Response
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate(['fiscal_year_id' => ['required', 'uuid']]);
        $tenantId = $this->tenantId();
        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($validated['fiscal_year_id']);
        $fileName = $exporter->fileName($tenantId, $fiscalYear);

        return response($exporter->build($tenantId, $fiscalYear)['content'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    #[OA\Get(
        path: '/api/v1/general-ledger/sii/{type}/export',
        operationId: 'exportGeneralLedgerSiiVatBook',
        description: 'CSV of the SII VAT book (Libro registro de IVA) of the active tenant for a period: facturas emitidas (sales) or facturas recibidas (purchases), one row per invoice and VAT rate, read from the posted journal entries.',
        summary: 'Export the SII VAT book',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Tax Settlements'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases'])),
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function sii(Request $request, string $type, VatRegisterService $registers, SiiVatBookExporter $exporter): Response
    {
        $this->authorize('viewRegisters', VatSettlement::class);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);
        $fileName = $exporter->fileName($type, $from, $to);

        return response($exporter->csv($registers->linesOf($this->tenantId(), $type, $from, $to), $type), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    private function tenantId(): string
    {
        $tenantId = $this->tenantContext->getTenantId();
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }
}
