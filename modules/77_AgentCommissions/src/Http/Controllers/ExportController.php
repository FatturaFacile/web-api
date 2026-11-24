<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\AgentCommissions\Exports\ComplianceExportService;
use Modules\AgentCommissions\Http\Controllers\Concerns\ResolvesAgentCommissionContext;
use Modules\AgentCommissions\Http\Requests\ExportRequest;
use OpenApi\Attributes as OA;

/**
 * Compliance exports: Certificazione Unica (CU) data and the Enasarco quarterly payment trace.
 */
final class ExportController extends Controller
{
    use ResolvesAgentCommissionContext;

    public function __construct(private readonly ComplianceExportService $exports) {}

    #[OA\Get(path: '/api/v1/agent-commissions/exports/cu', operationId: 'agentCommissionsExportCu', summary: 'Export the paid commissions of a year for the Certificazione Unica', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function cu(ExportRequest $request): Response
    {
        $year = (int) $request->validated('year');

        return $this->csv($this->exports->cuCsv($year, $this->locale($request)), 'cu-agents-'.$year.'.csv');
    }

    #[OA\Get(path: '/api/v1/agent-commissions/exports/enasarco', operationId: 'agentCommissionsExportEnasarco', summary: 'Export the Enasarco payment trace of a year or quarter', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Agent Commissions'], responses: [new OA\Response(response: 200, description: 'CSV file')])]
    public function enasarco(ExportRequest $request): Response
    {
        $year = (int) $request->validated('year');
        $quarter = isset($request->validated()['quarter']) ? (int) $request->validated('quarter') : null;

        return $this->csv($this->exports->enasarcoCsv($year, $quarter, $this->locale($request)), 'enasarco-'.$year.($quarter === null ? '' : '-q'.$quarter).'.csv');
    }

    private function csv(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
