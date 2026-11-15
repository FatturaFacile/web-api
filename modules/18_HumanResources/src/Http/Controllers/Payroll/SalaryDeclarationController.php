<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\HumanResources\Contracts\Payroll\SalaryDeclarationExporterInterface;
use Modules\HumanResources\DTOs\Payroll\GenerateSalaryDeclarationDTO;
use Modules\HumanResources\Http\Requests\Payroll\GenerateSalaryDeclarationRequest;
use Modules\HumanResources\Http\Resources\Payroll\SalaryDeclarationResource;
use Modules\HumanResources\Models\Payroll\SalaryDeclaration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SalaryDeclarationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SalaryDeclarationExporterInterface $exporter,
        private readonly TenantContext $tenantContext,
    ) {}

    public function store(GenerateSalaryDeclarationRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $runIds = $request->input('payroll_run_ids');
        $userId = $request->user()?->getAuthIdentifier();

        $declaration = $this->exporter->generate(new GenerateSalaryDeclarationDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            year: $request->integer('year'),
            payrollRunIds: is_array($runIds) ? array_values(array_map('strval', $runIds)) : null,
            actorUserId: $userId !== null ? (string) $userId : null,
        ));

        return $this->successResponse(new SalaryDeclarationResource($declaration), 'Salary declaration generated', Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $declaration = $this->findDeclaration($id);
        $this->authorize('view', $declaration);

        return $this->successResponse(new SalaryDeclarationResource($declaration), 'Salary declaration retrieved successfully');
    }

    public function download(string $id): StreamedResponse|JsonResponse
    {
        $declaration = $this->findDeclaration($id);
        $this->authorize('view', $declaration);

        $disk = Storage::disk($declaration->xml_disk);

        if (! $disk->exists($declaration->xml_path)) {
            return $this->problemDetailsResponse(
                title: 'Declaration File Missing',
                detail: "The XML file of salary declaration {$declaration->declaration_number} is no longer available.",
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return $disk->download($declaration->xml_path, $declaration->declaration_number.'.xml', [
            'Content-Type' => 'application/xml',
            'X-Content-SHA256' => $declaration->xml_sha256,
        ]);
    }

    private function findDeclaration(string $id): SalaryDeclaration
    {
        return SalaryDeclaration::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }
}
