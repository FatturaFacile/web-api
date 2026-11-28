<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Queries\DatasetExporter;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use OpenApi\Attributes as OA;

/**
 * CSV and PDF export of the registers (same filters as the lists).
 */
final class ExportController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/exports/{dataset}.{format}', operationId: 'bondedWarehousesExport', summary: 'Export mrn-lots, cgu-ledger, vat-register or aeo-audits as CSV or PDF', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'File')])]
    public function export(string $dataset, string $format, RegistryQueries $queries, DatasetExporter $exporter): Response
    {
        Gate::authorize('viewAny', BondedMrnLot::class);

        $table = $exporter->table($dataset, $this->filters(), $queries);
        $name = 'bonded-warehouses-'.$dataset.'-'.now()->format('Ymd');

        if ($format === 'pdf') {
            return response($exporter->pdf($dataset, $table), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"']);
        }

        return response($exporter->csv($table), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.csv"']);
    }
}
