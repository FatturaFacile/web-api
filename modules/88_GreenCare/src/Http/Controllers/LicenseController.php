<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Domain\Enums\LicenseType;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\SaveLicenseRequest;
use Modules\GreenCare\Http\Resources\LicenseResource;
use Modules\GreenCare\Models\OperatorPhytosanitaryLicense;
use OpenApi\Attributes as OA;

/**
 * Phytosanitary licenses of the operators: PER / PNRP / OPF (Switzerland), patentino PAN (Italy).
 */
final class LicenseController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/green-care/licenses', operationId: 'greenCareLicensesIndex', summary: 'Operator licenses', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Licenses')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', OperatorPhytosanitaryLicense::class);
        $today = now()->toDateString();
        $page = OperatorPhytosanitaryLicense::query()
            ->with('operator')
            ->when(request()->query('operator_id') !== null, static fn ($query) => $query->where('crew_team_member_id', (string) request()->query('operator_id')))
            ->when(filter_var(request()->query('only_valid', false), FILTER_VALIDATE_BOOLEAN), static fn ($query) => $query->where('is_active', true)->whereDate('expiry_date', '>=', $today)->whereDate('issue_date', '<=', $today))
            ->orderBy('expiry_date')
            ->paginate($this->perPage(100));

        return $this->paged($page, LicenseResource::class);
    }

    #[OA\Post(path: '/api/v1/green-care/licenses', operationId: 'greenCareLicensesStore', summary: 'Register an operator license', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'License')])]
    public function store(SaveLicenseRequest $request): JsonResponse
    {
        Gate::authorize('create', OperatorPhytosanitaryLicense::class);
        $license = OperatorPhytosanitaryLicense::query()->create($this->attributes($request->validated()) + ['tenant_id' => $this->tenantId()]);

        return $this->createdResponse(new LicenseResource($license->load('operator')), $this->message('license_saved'));
    }

    #[OA\Put(path: '/api/v1/green-care/licenses/{id}', operationId: 'greenCareLicensesUpdate', summary: 'Change an operator license', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'License')])]
    public function update(SaveLicenseRequest $request, string $id): JsonResponse
    {
        $license = OperatorPhytosanitaryLicense::query()->findOrFail($id);
        Gate::authorize('update', $license);
        $license->update($this->attributes($request->validated()));

        return $this->successResponse(new LicenseResource($license->refresh()->load('operator')), $this->message('license_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/licenses/{id}', operationId: 'greenCareLicensesDestroy', summary: 'Delete an operator license', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $license = OperatorPhytosanitaryLicense::query()->findOrFail($id);
        Gate::authorize('delete', $license);
        $license->delete();

        return $this->successResponse(null, $this->message('license_deleted'));
    }

    /**
     * The jurisdiction of a license follows its type (PAN is Italian, PER / PNRP / OPF are Swiss).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $data['jurisdiction'] = LicenseType::from((string) $data['license_type'])->jurisdiction();

        return $data;
    }
}
