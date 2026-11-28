<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\WithholdingRecipients\Application\Commands\CalculateRecipientCompensationWithholdingCommand;
use Modules\WithholdingRecipients\Application\Commands\CalculateRecipientCompensationWithholdingHandler;
use Modules\WithholdingRecipients\Http\Requests\SimulateCompensationRequest;
use OpenApi\Attributes as OA;

/**
 * Compensation and fee-note simulator: the full tax breakdown of an amount, without posting anything.
 */
final class CompensationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CalculateRecipientCompensationWithholdingHandler $calculate) {}

    #[OA\Post(path: '/api/v1/withholding-recipients/compensations/simulate', operationId: 'withholdingRecipientsSimulate', summary: 'Simulate the withholdings and the net payable amount of a compensation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Withholding Recipients'], responses: [new OA\Response(response: 200, description: 'Calculation result')])]
    public function simulate(SimulateCompensationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->calculate->handle(new CalculateRecipientCompensationWithholdingCommand($data, $data['recipient_profile_id'] ?? null));

        return $this->successResponse($result->toArray());
    }
}
