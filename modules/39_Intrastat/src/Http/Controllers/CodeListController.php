<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Intrastat\Models\IntrastatDeclaration;
use Modules\Intrastat\Support\IntrastatCodes;
use OpenApi\Attributes as OA;

final class CodeListController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/intrastat/codes', operationId: 'listIntrastatCodes', description: 'Return the code lists of the INTRA models, as published in the instructions (Allegato XI to determinazione ADM 493869/RU del 23/12/2021): member states and VAT code lengths, nature of the transaction, transport modes, delivery term groups, supply modes, payment methods, content and special case codes of the frontespizio.', summary: 'List INTRA codes', security: [['bearerAuth' => []]], tags: ['Intrastat'], responses: [
        new OA\Response(response: 200, description: 'Code lists', content: new OA\JsonContent(ref: '#/components/schemas/IntrastatCodesResponse')),
        new OA\Response(ref: '#/components/responses/IntrastatUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/IntrastatForbidden', response: 403),
    ])]
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', IntrastatDeclaration::class);

        return $this->successResponse(IntrastatCodes::catalogue(), 'INTRA code lists');
    }
}
