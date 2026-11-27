<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Commands\GenerateGreenCareBillingCommand;
use Modules\GreenCare\Application\Handlers\GenerateGreenCareBillingHandler;
use Modules\GreenCare\Application\Services\ContractWriter;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\BillingRequest;
use Modules\GreenCare\Http\Requests\NoInputRequest;
use Modules\GreenCare\Http\Requests\SaveContractRequest;
use Modules\GreenCare\Http\Resources\ContractResource;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;
use OpenApi\Attributes as OA;

/**
 * Seasonal maintenance contracts (condominiums included): passage counter, lifecycle and billing.
 */
final class ContractController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['site', 'customer', 'condominiumAdministrator'];

    #[OA\Get(path: '/api/v1/green-care/contracts', operationId: 'greenCareContractsIndex', summary: 'Seasonal maintenance contracts', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Contracts')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SeasonalMaintenanceContract::class);

        $search = trim((string) request()->query('search', ''));
        $page = SeasonalMaintenanceContract::query()
            ->with(self::WITH)
            ->when(request()->query('status') !== null, static fn ($query) => $query->where('status', (string) request()->query('status')))
            ->when(request()->query('site_id') !== null, static fn ($query) => $query->where('site_id', (string) request()->query('site_id')))
            ->when(request()->query('customer_id') !== null, static fn ($query) => $query->where('customer_id', (string) request()->query('customer_id')))
            ->when($search !== '', static fn ($query) => $query->whereRaw('LOWER(contract_number) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->orderByDesc('season_start_date')
            ->orderBy('contract_number')
            ->paginate($this->perPage());

        return $this->paged($page, ContractResource::class);
    }

    #[OA\Post(path: '/api/v1/green-care/contracts', operationId: 'greenCareContractsStore', summary: 'Create a seasonal contract (draft)', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Contract')])]
    public function store(SaveContractRequest $request, ContractWriter $writer): JsonResponse
    {
        Gate::authorize('create', SeasonalMaintenanceContract::class);
        $contract = $writer->create($request->validated(), $this->tenantId());

        return $this->createdResponse(new ContractResource($contract->load(self::WITH)), $this->message('contract_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/contracts/{id}', operationId: 'greenCareContractsShow', summary: 'One contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function show(string $id): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('view', $contract);

        return $this->successResponse(new ContractResource($contract), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/green-care/contracts/{id}', operationId: 'greenCareContractsUpdate', summary: 'Change a contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function update(SaveContractRequest $request, string $id, ContractWriter $writer): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);

        return $this->successResponse(new ContractResource($writer->update($contract, $request->validated())->load(self::WITH)), $this->message('contract_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/contracts/{id}', operationId: 'greenCareContractsDestroy', summary: 'Delete a contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('delete', $contract);
        $contract->delete();

        return $this->successResponse(null, $this->message('contract_deleted'));
    }

    #[OA\Post(path: '/api/v1/green-care/contracts/{id}/activate', operationId: 'greenCareContractsActivate', summary: 'Activate a draft or suspended contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function activate(NoInputRequest $request, string $id, ContractWriter $writer): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);

        return $this->successResponse(new ContractResource($writer->activate($contract)->load(self::WITH)), $this->message('contract_activated'));
    }

    #[OA\Post(path: '/api/v1/green-care/contracts/{id}/suspend', operationId: 'greenCareContractsSuspend', summary: 'Suspend an active contract', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Contract')])]
    public function suspend(NoInputRequest $request, string $id, ContractWriter $writer): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);

        return $this->successResponse(new ContractResource($writer->suspend($contract)->load(self::WITH)), $this->message('contract_suspended'));
    }

    #[OA\Post(path: '/api/v1/green-care/contracts/{id}/billing-preview', operationId: 'greenCareContractsBillingPreview', summary: 'Preview of the invoice (lines, VAT and the 4% withholding of the Italian condominiums)', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Preview')])]
    public function billingPreview(BillingRequest $request, string $id, GenerateGreenCareBillingHandler $handler): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('view', $contract);

        return $this->successResponse($handler->handle($this->command($request, $contract->id, true)), $this->message('billing_previewed'));
    }

    #[OA\Post(path: '/api/v1/green-care/contracts/{id}/generate-invoice', operationId: 'greenCareContractsGenerateInvoice', summary: 'Generate the draft invoice of the contract in the invoicing module', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Invoice')])]
    public function generateInvoice(BillingRequest $request, string $id, GenerateGreenCareBillingHandler $handler): JsonResponse
    {
        $contract = SeasonalMaintenanceContract::query()->findOrFail($id);
        Gate::authorize('update', $contract);

        return $this->createdResponse($handler->handle($this->command($request, $contract->id, false)), $this->message('invoice_generated'));
    }

    private function command(BillingRequest $request, string $contractId, bool $dryRun): GenerateGreenCareBillingCommand
    {
        $data = $request->validated();

        return new GenerateGreenCareBillingCommand(
            contractId: $contractId,
            periodStart: isset($data['period_start']) ? Carbon::parse($data['period_start']) : null,
            periodEnd: isset($data['period_end']) ? Carbon::parse($data['period_end']) : null,
            includeInstallment: (bool) ($data['include_installment'] ?? true),
            includeSnowStandby: (bool) ($data['include_snow_standby'] ?? true),
            includeExtraPassages: (bool) ($data['include_extra_passages'] ?? true),
            includeTimeAndMaterial: (bool) ($data['include_time_and_material'] ?? true),
            includeWaste: (bool) ($data['include_waste'] ?? true),
            dryRun: $dryRun,
            userId: $this->userId(),
        );
    }
}
