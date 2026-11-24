<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Modules\ItalianVat\Http\Controllers\Concerns\ResolvesItalianVatContext;
use Modules\ItalianVat\Http\Requests\DefinitivePrintRequest;
use Modules\ItalianVat\Http\Requests\PrintListRequest;
use Modules\ItalianVat\Http\Requests\ProRataRequest;
use Modules\ItalianVat\Http\Requests\RecordEntryRequest;
use Modules\ItalianVat\Http\Requests\RegisterPeriodRequest;
use Modules\ItalianVat\Http\Requests\ReverseEntryRequest;
use Modules\ItalianVat\Http\Resources\VatProRataRateResource;
use Modules\ItalianVat\Http\Resources\VatRegisterEntryResource;
use Modules\ItalianVat\Http\Resources\VatRegisterPrintResource;
use Modules\ItalianVat\Services\ProRataService;
use Modules\ItalianVat\Services\VatRegisterService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sales and purchases VAT registers, their definitive print and the pro-rata of deduction.
 */
final class VatRegisterController extends Controller
{
    use ApiResponse;
    use ResolvesItalianVatContext;

    public function __construct(
        private readonly VatRegisterService $registers,
        private readonly ProRataService $proRata,
    ) {}

    #[OA\Get(path: '/api/v1/italian-vat/registers/{sectionId}', operationId: 'getItalianVatRegister', summary: 'A register sectional with its totals', description: 'The lines of the sectional in protocol order, with the totals per VAT rate and per nature of the operations without VAT.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'sectionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'The register')])]
    public function show(RegisterPeriodRequest $request, string $sectionId): JsonResponse
    {
        $summary = $this->registers->summary(
            $this->tenantId(),
            $sectionId,
            CarbonImmutable::parse((string) $request->validated('from')),
            CarbonImmutable::parse((string) $request->validated('to')),
        );

        return $this->successResponse([
            'section' => ['id' => $summary['section']->id, 'code' => $summary['section']->code, 'name' => $summary['section']->name, 'register_type' => $summary['section']->register_type],
            'entries' => VatRegisterEntryResource::collection($summary['entries']),
            'by_rate' => $summary['by_rate'],
            'by_nature' => $summary['by_nature'],
            'totals' => $summary['totals'],
        ]);
    }

    #[OA\Post(path: '/api/v1/italian-vat/register-entries', operationId: 'createItalianVatRegisterEntry', summary: 'Register a document', description: 'Assigns the next progressive protocol number of the sectional and year. Purchases split the VAT in deductible and non-deductible with the pro-rata. A period closed by a definitive print refuses new lines.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 201, description: 'The register line'), new OA\Response(response: 409, description: 'The period is locked'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(RecordEntryRequest $request): JsonResponse
    {
        $entry = $this->registers->record($this->tenantId(), $this->userId($request), $request->validated());

        return $this->successResponse(new VatRegisterEntryResource($entry), 'Entry registered', Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/italian-vat/register-entries/{id}/reverse', operationId: 'reverseItalianVatRegisterEntry', summary: 'Reverse a register line', description: 'A printed line is never altered: it is cancelled with an opposite line in the open period.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'The opposite line'), new OA\Response(response: 409, description: 'The period is locked')])]
    public function reverse(ReverseEntryRequest $request, string $id): JsonResponse
    {
        $entry = $this->registers->reverse($this->tenantId(), $this->userId($request), $id, (string) $request->validated('entry_date'), $request->validated('reason'));

        return $this->successResponse(new VatRegisterEntryResource($entry), 'Entry reversed', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/italian-vat/register-entries/{id}', operationId: 'deleteItalianVatRegisterEntry', summary: 'Delete a line that is not printed', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 409, description: 'The line is locked by a definitive print')])]
    public function destroy(string $id): JsonResponse
    {
        $this->registers->delete($this->tenantId(), $id);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Post(path: '/api/v1/italian-vat/registers/{sectionId}/prints', operationId: 'printItalianVatRegisterDefinitively', summary: 'Definitive print of a register', description: 'Numbers the pages, fixes the content hash, records the virtual stamp and locks every printed line.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'sectionId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'The print'), new OA\Response(response: 422, description: 'Nothing to print, or a gap in the protocol numbers')])]
    public function print(DefinitivePrintRequest $request, string $sectionId): JsonResponse
    {
        $print = $this->registers->definitivePrint($this->tenantId(), $this->userId($request), $sectionId, (int) $request->validated('year'), (string) $request->validated('period_to'));

        return $this->successResponse(new VatRegisterPrintResource($print), 'Register printed', Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/italian-vat/register-prints', operationId: 'listItalianVatRegisterPrints', summary: 'List the definitive prints', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], responses: [new OA\Response(response: 200, description: 'The prints')])]
    public function prints(PrintListRequest $request): JsonResponse
    {
        $year = $request->validated('year');

        return $this->successResponse(VatRegisterPrintResource::collection($this->registers->prints($this->tenantId(), $request->validated('section_id'), $year === null ? null : (int) $year)));
    }

    #[OA\Get(path: '/api/v1/italian-vat/register-prints/{id}/verify', operationId: 'verifyItalianVatRegisterPrint', summary: 'Check that a printed register was not altered', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The verification result')])]
    public function verify(string $id): JsonResponse
    {
        return $this->successResponse($this->registers->verifyPrint($this->tenantId(), $id));
    }

    #[OA\Get(path: '/api/v1/italian-vat/register-prints/{id}/pdf', operationId: 'downloadItalianVatRegisterPrintPdf', summary: 'PDF of a definitive print', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The PDF', content: new OA\MediaType(mediaType: 'application/pdf'))])]
    public function pdf(string $id): HttpResponse
    {
        $file = $this->registers->printPdf($this->tenantId(), $id);

        return $this->download($file['content'], $file['file_name'], 'application/pdf');
    }

    #[OA\Get(path: '/api/v1/italian-vat/pro-rata/{year}', operationId: 'getItalianVatProRata', summary: 'Pro-rata of deduction of a year', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The recorded rate and the percentage in force')])]
    public function proRata(string $year): JsonResponse
    {
        $tenantId = $this->tenantId();
        $rate = $this->proRata->rateOf($tenantId, (int) $year);

        return $this->successResponse([
            'rate' => $rate === null ? null : new VatProRataRateResource($rate),
            'percentage_in_force' => $this->proRata->percentageFor($tenantId, (int) $year),
        ]);
    }

    #[OA\Put(path: '/api/v1/italian-vat/pro-rata/{year}', operationId: 'saveItalianVatProRata', summary: 'Record the operations of a year and compute the pro-rata', description: 'The percentage is the ratio between the operations that give the right to deduct and all the operations, rounded up to the next unit. With definitive=true the year is closed.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Italian VAT'], parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'The rate'), new OA\Response(response: 409, description: 'The year is closed')])]
    public function saveProRata(ProRataRequest $request, string $year): JsonResponse
    {
        $rate = $this->proRata->record(
            $this->tenantId(),
            (int) $year,
            (string) $request->validated('deductible_operations'),
            (string) $request->validated('total_operations'),
            (bool) $request->validated('definitive', false),
        );

        return $this->successResponse(new VatProRataRateResource($rate), 'Pro-rata saved');
    }
}
