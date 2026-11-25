<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Commands\AddMileageClaimCommand;
use Modules\ExpenseManagement\Application\Commands\AddMileageClaimHandler;
use Modules\ExpenseManagement\Application\Commands\RemoveExpenseLineCommand;
use Modules\ExpenseManagement\Application\Commands\RemoveExpenseLineHandler;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\MileageClaimRequest;
use Modules\ExpenseManagement\Http\Resources\LineResource;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\MileageClaim;
use Modules\ExpenseManagement\Services\MileageService;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use OpenApi\Attributes as OA;

/**
 * Mileage claims: the quote of the rate and amount, and the claims of a report (each one is an expense line).
 */
final class MileageController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Post(path: '/api/v1/expense-management/mileage-claims/quote', operationId: 'expenseManagementMileageQuote', summary: 'Quote a mileage claim: rate per km, kilometres and amount', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Quote')])]
    public function quote(MileageClaimRequest $request, MileageService $mileage): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);
        $jurisdiction = FiscalStrategyRegistry::jurisdictionOfCountry(Tenant::query()->whereKey($this->tenantId())->value('country'));

        return $this->successResponse($mileage->quote($jurisdiction, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/mileage-claims', operationId: 'expenseManagementAddMileageClaim', summary: 'Add a mileage claim to a report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Mileage claim added as an expense line')])]
    public function store(MileageClaimRequest $request, string $id, AddMileageClaimHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $line = $handler->handle(new AddMileageClaimCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new LineResource($line), $this->message('saved'), 201);
    }

    #[OA\Delete(path: '/api/v1/expense-management/reports/{id}/mileage-claims/{claimId}', operationId: 'expenseManagementRemoveMileageClaim', summary: 'Remove a mileage claim and its expense line', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'claimId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Mileage claim removed')])]
    public function destroy(string $id, string $claimId, RemoveExpenseLineHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $claim = MileageClaim::query()->findOrFail($claimId);
        $handler->handle(new RemoveExpenseLineCommand($id, (string) $claim->expense_line_id, $this->userId()));

        return $this->successResponse(null, $this->message('deleted'));
    }
}
