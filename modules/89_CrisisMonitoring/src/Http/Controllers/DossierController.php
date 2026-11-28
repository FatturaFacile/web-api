<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\CrisisMonitoring\Application\Commands\GenerateBoardAdequateAssetsDossierPdfCommand;
use Modules\CrisisMonitoring\Application\Handlers\GenerateBoardAdequateAssetsDossierPdfHandler;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Http\Controllers\Concerns\ResolvesCrisisContext;
use Modules\CrisisMonitoring\Http\Requests\CrisisQueryRequest;
use OpenApi\Attributes as OA;

/**
 * The quarterly dossier for the board and the statutory auditors (Art. 2086 c.c.).
 */
final class DossierController extends Controller
{
    use ResolvesCrisisContext;

    #[OA\Get(path: '/api/v1/crisis-monitoring/dossier/pdf', operationId: 'downloadCrisisMonitoringDossierPdf', summary: 'Board dossier on the adequate organisational arrangements (PDF)', description: 'Fascicolo periodico Art. 2086 c.c. with the DSCR curves, the seven indicators, the Art. 25-novies matrix, the stress sensitivity and the signature block.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The PDF document')])]
    public function pdf(CrisisQueryRequest $request, GenerateBoardAdequateAssetsDossierPdfHandler $handler): Response
    {
        $locale = CrisisTexts::locale($request->validated('locale'));
        $content = $handler->handle(new GenerateBoardAdequateAssetsDossierPdfCommand($this->tenant(), $locale, $request->asOf()));

        return $this->pdfDownload($content, 'adequate-organizational-assets-dossier-'.now()->format('Ymd').'.pdf');
    }
}
