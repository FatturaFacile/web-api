<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Contracts\Payroll\PayrollRunServiceInterface;
use Modules\HumanResources\DTOs\Payroll\CreatePayrollRunDTO;
use Modules\HumanResources\DTOs\Payroll\UpdatePayrollRunDTO;
use Modules\HumanResources\Http\Requests\Payroll\StorePayrollRunRequest;
use Modules\HumanResources\Http\Requests\Payroll\UpdatePayrollRunRequest;
use Modules\HumanResources\Http\Resources\Payroll\PayrollRunResource;
use Modules\HumanResources\Http\Resources\Payroll\PayslipResource;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Symfony\Component\HttpFoundation\Response;

final class PayrollRunController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PayrollRunServiceInterface $runService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->where('tenant_id', $this->tenantId())
            ->when($request->filled('year'), fn ($query) => $query->where('period_year', $request->integer('year')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->successResponse(
            PayrollRunResource::collection($runs->getCollection()),
            'Payroll runs retrieved successfully',
            Response::HTTP_OK,
            ['current_page' => $runs->currentPage(), 'per_page' => $runs->perPage(), 'total' => $runs->total()],
        );
    }

    public function store(StorePayrollRunRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $employeeIds = $request->input('employee_ids');

        $run = $this->runService->createRun(new CreatePayrollRunDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            periodYear: $request->integer('period_year'),
            periodMonth: $request->integer('period_month'),
            paymentDate: $request->filled('payment_date') ? $request->string('payment_date')->value() : null,
            employeeIds: is_array($employeeIds) ? array_values(array_map('strval', $employeeIds)) : null,
            inputs: $request->payrollInputs(),
            notes: $request->filled('notes') ? $request->string('notes')->value() : null,
        ), $this->actorId($request));

        return $this->successResponse(new PayrollRunResource($run), 'Payroll run created', Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('view', $run);

        return $this->successResponse(new PayrollRunResource($run), 'Payroll run retrieved successfully');
    }

    public function update(UpdatePayrollRunRequest $request, string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('update', $run);

        $employeeIds = $request->input('employee_ids');

        $updated = $this->runService->updateRun($run, new UpdatePayrollRunDTO(
            paymentDate: $request->filled('payment_date') ? $request->string('payment_date')->value() : null,
            employeeIds: is_array($employeeIds) ? array_values(array_map('strval', $employeeIds)) : null,
            inputs: $request->payrollInputs(),
            notes: $request->filled('notes') ? $request->string('notes')->value() : null,
        ), $this->actorId($request));

        return $this->successResponse(new PayrollRunResource($updated), 'Payroll run updated');
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('calculate', $run);

        $calculated = $this->runService->calculate($run, $this->actorId($request));

        return $this->successResponse(new PayrollRunResource($calculated), 'Payroll run calculated');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('approve', $run);

        $approved = $this->runService->approve($run, $this->actorId($request));

        return $this->successResponse(new PayrollRunResource($approved), 'Payroll run approved');
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('close', $run);

        $closed = $this->runService->close($run, $this->actorId($request));

        return $this->successResponse(new PayrollRunResource($closed), 'Payroll run closed');
    }

    public function payslips(string $id): JsonResponse
    {
        $run = $this->findRun($id);
        $this->authorize('view', $run);

        $payslips = Payslip::query()
            ->with('lines')
            ->where('tenant_id', $this->tenantId())
            ->where('payroll_run_id', $run->id)
            ->orderBy('created_at')
            ->get();

        return $this->successResponse(PayslipResource::collection($payslips), 'Payslips retrieved successfully');
    }

    public function showPayslip(string $id): JsonResponse
    {
        $payslip = Payslip::query()->with('lines')->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('view', $payslip);

        return $this->successResponse(new PayslipResource($payslip), 'Payslip retrieved successfully');
    }

    private function findRun(string $id): PayrollRun
    {
        return PayrollRun::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function actorId(Request $request): ?string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $userId !== null ? (string) $userId : null;
    }
}
