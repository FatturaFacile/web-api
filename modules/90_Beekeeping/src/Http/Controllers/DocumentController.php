<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Beekeeping\Documents\BeekeepingDocumentRenderer;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Models\NomadismMovement;
use Modules\Beekeeping\Models\VarroaTreatment;
use OpenApi\Attributes as OA;

/**
 * PDF documents: hive QR label sheet, jar label, veterinary treatment register, movement document, census declaration and HACCP batch sheet.
 */
final class DocumentController extends Controller
{
    use ResolvesBeekeepingContext;

    public function __construct(private readonly BeekeepingDocumentRenderer $documents) {}

    #[OA\Get(path: '/api/v1/beekeeping/documents/hive-qr-sheet', operationId: 'beekeepingDocQrSheet', summary: 'Printable QR label sheet of hives', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function qrSheet(Request $request): Response
    {
        $this->authorizeRead();
        $apiaryId = $request->query('apiary_id');
        $hiveIds = array_filter((array) $request->query('hive_ids', []), 'is_string');
        $hives = Hive::query()->with('apiary:id,code,name')
            ->when(is_string($apiaryId) && $apiaryId !== '', static fn ($query) => $query->where('apiary_id', $apiaryId))
            ->when($hiveIds !== [], static fn ($query) => $query->whereIn('id', $hiveIds))
            ->orderBy('code')->limit(400)->get();

        return $this->pdf($this->documents->hiveQrSheet($hives, $this->locale($request)), 'hive-qr-sheet.pdf');
    }

    #[OA\Get(path: '/api/v1/beekeeping/documents/jarring-runs/{id}/label', operationId: 'beekeepingDocJarLabel', summary: 'Jar label of a jarring run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function jarLabel(Request $request, string $id): Response
    {
        $this->authorizeRead();
        $run = JarringRun::query()->with('batch')->findOrFail($id);

        return $this->pdf($this->documents->jarLabel($run, $this->locale($request)), 'jar-label-'.$run->jar_lot_code.'.pdf');
    }

    #[OA\Get(path: '/api/v1/beekeeping/documents/varroa-register', operationId: 'beekeepingDocVarroaRegister', summary: 'Official veterinary treatment register', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function varroaRegister(Request $request): Response
    {
        $this->authorizeRead();
        $year = $request->query('year');
        $apiaryId = $request->query('apiary_id');
        $treatments = VarroaTreatment::query()->with('apiary:id,code,name')
            ->when(is_numeric($year), static fn ($query) => $query->whereYear('treatment_date', (int) $year))
            ->when(is_string($apiaryId) && $apiaryId !== '', static fn ($query) => $query->where('apiary_id', $apiaryId))
            ->orderBy('treatment_date')->get();

        return $this->pdf($this->documents->varroaRegister($treatments, $this->locale($request), is_numeric($year) ? (int) $year : null), 'varroa-register.pdf');
    }

    #[OA\Get(path: '/api/v1/beekeeping/documents/movements/{id}', operationId: 'beekeepingDocMovement', summary: 'Movement accompanying document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function movement(Request $request, string $id): Response
    {
        $this->authorizeRead();
        $movement = NomadismMovement::query()->with(['origin', 'destination'])->findOrFail($id);

        return $this->pdf($this->documents->movementDocument($movement, $this->locale($request)), $movement->document_number.'.pdf');
    }

    #[OA\Get(path: '/api/v1/beekeeping/documents/census/{id}', operationId: 'beekeepingDocCensus', summary: 'Census declaration PDF', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function census(Request $request, string $id): Response
    {
        $this->authorizeRead();
        $census = CensusDeclaration::query()->findOrFail($id);

        return $this->pdf($this->documents->censusDeclaration($census, $this->locale($request)), 'census-'.$census->country.'-'.$census->census_year.'.pdf');
    }

    #[OA\Get(path: '/api/v1/beekeeping/documents/batches/{id}/haccp', operationId: 'beekeepingDocHaccp', summary: 'HACCP sheet of an extraction batch', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF')])]
    public function haccp(Request $request, string $id): Response
    {
        $this->authorizeRead();
        $batch = ExtractionBatch::query()->findOrFail($id);

        return $this->pdf($this->documents->haccpSheet($batch, $batch->intakes()->with('apiary:id,code')->get(), $this->locale($request)), 'haccp-'.$batch->lot_code.'.pdf');
    }

    private function pdf(string $binary, string $filename): Response
    {
        return response($binary, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }
}
