<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Services\DocumentNumbering;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\SaveWasteSlipRequest;
use Modules\GreenCare\Http\Resources\WasteSlipResource;
use Modules\GreenCare\Http\Support\ContactName;
use Modules\GreenCare\Models\GreenWasteSlip;
use OpenApi\Attributes as OA;

/**
 * Green waste disposal slips (CER 20 02 01 / OTR): weights, composting facilities, transport slip numbers and pass-through billing.
 */
final class WasteSlipController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    private const array WITH = ['site', 'compostFacility'];

    #[OA\Get(path: '/api/v1/green-care/slips', operationId: 'greenCareSlipsIndex', summary: 'Green waste slips', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Slips')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', GreenWasteSlip::class);

        return $this->paged($this->filtered()->with(self::WITH)->orderByDesc('disposal_date')->orderByDesc('slip_number')->paginate($this->perPage()), WasteSlipResource::class);
    }

    #[OA\Get(path: '/api/v1/green-care/slips/summary', operationId: 'greenCareSlipsSummary', summary: 'Weights by waste code and by composting facility', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Summary')])]
    public function summary(): JsonResponse
    {
        Gate::authorize('viewAny', GreenWasteSlip::class);
        $slips = $this->filtered()->with('compostFacility')->get();

        return $this->successResponse([
            'total_kg' => round((float) $slips->sum('green_waste_weight_kg'), 2),
            'billable_cost' => round((float) $slips->where('is_billable_to_customer', true)->sum('disposal_cost'), 2),
            'by_waste_code' => $slips->groupBy('waste_code')->map(static fn ($group, $code): array => [
                'waste_code' => (string) $code,
                'kg' => round((float) $group->sum('green_waste_weight_kg'), 2),
                'slips' => $group->count(),
            ])->values()->all(),
            'by_facility' => $slips->groupBy(static fn (GreenWasteSlip $slip): string => (string) $slip->compost_facility_id)->map(static fn ($group): array => [
                'compost_facility_id' => $group->first()->compost_facility_id,
                'name' => ContactName::of($group->first()->compostFacility),
                'kg' => round((float) $group->sum('green_waste_weight_kg'), 2),
                'slips' => $group->count(),
            ])->values()->all(),
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/green-care/slips', operationId: 'greenCareSlipsStore', summary: 'Register a green waste slip', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Slip')])]
    public function store(SaveWasteSlipRequest $request, DocumentNumbering $numbering): JsonResponse
    {
        Gate::authorize('create', GreenWasteSlip::class);
        $data = $request->validated();
        $data['slip_number'] = ($data['slip_number'] ?? null) ?: $numbering->nextWasteSlipNumber(Carbon::parse($data['disposal_date']));
        $slip = GreenWasteSlip::query()->create($data + ['tenant_id' => $this->tenantId()]);

        return $this->createdResponse(new WasteSlipResource($slip->load(self::WITH)), $this->message('slip_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/slips/{id}', operationId: 'greenCareSlipsShow', summary: 'One slip', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Slip')])]
    public function show(string $id): JsonResponse
    {
        $slip = GreenWasteSlip::query()->with(self::WITH)->findOrFail($id);
        Gate::authorize('view', $slip);

        return $this->successResponse(new WasteSlipResource($slip), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/green-care/slips/{id}', operationId: 'greenCareSlipsUpdate', summary: 'Change a slip that is not invoiced yet', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Slip')])]
    public function update(SaveWasteSlipRequest $request, string $id): JsonResponse
    {
        $slip = GreenWasteSlip::query()->findOrFail($id);
        Gate::authorize('update', $slip);
        $data = $request->validated();

        if (empty($data['slip_number'])) {
            unset($data['slip_number']);
        }

        $slip->update($data);

        return $this->successResponse(new WasteSlipResource($slip->refresh()->load(self::WITH)), $this->message('slip_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/slips/{id}', operationId: 'greenCareSlipsDestroy', summary: 'Delete a slip', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $slip = GreenWasteSlip::query()->findOrFail($id);
        Gate::authorize('delete', $slip);
        $slip->delete();

        return $this->successResponse(null, $this->message('slip_deleted'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<GreenWasteSlip>
     */
    private function filtered(): \Illuminate\Database\Eloquent\Builder
    {
        return GreenWasteSlip::query()
            ->when(request()->query('from') !== null, static fn ($query) => $query->whereDate('disposal_date', '>=', (string) request()->query('from')))
            ->when(request()->query('to') !== null, static fn ($query) => $query->whereDate('disposal_date', '<=', (string) request()->query('to')))
            ->when(request()->query('site_id') !== null, static fn ($query) => $query->where('site_id', (string) request()->query('site_id')))
            ->when(request()->query('waste_code') !== null, static fn ($query) => $query->where('waste_code', (string) request()->query('waste_code')));
    }
}
