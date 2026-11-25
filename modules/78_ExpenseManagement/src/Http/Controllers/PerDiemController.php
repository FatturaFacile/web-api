<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\ExpenseManagement\Application\Commands\ConfigurePerDiemAllowanceCommand;
use Modules\ExpenseManagement\Application\Commands\ConfigurePerDiemAllowanceHandler;
use Modules\ExpenseManagement\Http\Controllers\Concerns\InteractsWithExpenses;
use Modules\ExpenseManagement\Http\Requests\PerDiemRequest;
use Modules\ExpenseManagement\Http\Resources\PerDiemResource;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\PerDiemService;
use Modules\ExpenseManagement\Services\PerDiemTrail;
use Modules\ExpenseManagement\Strategies\FiscalStrategyRegistry;
use OpenApi\Attributes as OA;

/**
 * Per-diem allowances of a report: the preview of the calculation, and the allowances stored on the report.
 */
final class PerDiemController extends Controller
{
    use ApiResponse;
    use InteractsWithExpenses;

    #[OA\Post(path: '/api/v1/expense-management/per-diems/preview', operationId: 'expenseManagementPerDiemPreview', summary: 'Calculate a per-diem allowance without saving it', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], responses: [new OA\Response(response: 200, description: 'Allowance, tax-free limit and taxable excess')])]
    public function preview(PerDiemRequest $request, PerDiemService $perDiems): JsonResponse
    {
        Gate::authorize('viewAny', ExpenseReport::class);
        $jurisdiction = FiscalStrategyRegistry::jurisdictionOfCountry(Tenant::query()->whereKey($this->tenantId())->value('country'));

        return $this->successResponse($perDiems->calculate($jurisdiction, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/expense-management/reports/{id}/per-diems', operationId: 'expenseManagementAddPerDiem', summary: 'Add a per-diem allowance to a report', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Allowance added')])]
    public function store(PerDiemRequest $request, string $id, ConfigurePerDiemAllowanceHandler $handler): JsonResponse
    {
        $this->findReport($id, 'update');
        $allowance = $handler->handle(new ConfigurePerDiemAllowanceCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new PerDiemResource($allowance), $this->message('saved'), 201);
    }

    #[OA\Delete(path: '/api/v1/expense-management/reports/{id}/per-diems/{perDiemId}', operationId: 'expenseManagementRemovePerDiem', summary: 'Remove a per-diem allowance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Expense Management'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'perDiemId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Allowance removed')])]
    public function destroy(string $id, string $perDiemId, PerDiemTrail $trail): JsonResponse
    {
        $trail->remove($this->findReport($id, 'update'), $perDiemId, $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }
}
