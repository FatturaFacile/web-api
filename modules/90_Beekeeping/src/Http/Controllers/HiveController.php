<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Beekeeping\Domain\Enums\HiveType;
use Modules\Beekeeping\Http\Controllers\Concerns\ResolvesBeekeepingContext;
use Modules\Beekeeping\Http\Requests\SaveHiveRequest;
use Modules\Beekeeping\Http\Resources\HiveResource;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Services\VarroaRegisterService;
use Modules\Beekeeping\Services\WithdrawalGuard;
use OpenApi\Attributes as OA;

/**
 * Hive and nucleus registry with queen tracking, colony alerts and the withdrawal lock of each hive.
 */
final class HiveController extends Controller
{
    use ApiResponse;
    use ResolvesBeekeepingContext;

    public function __construct(private readonly WithdrawalGuard $guard, private readonly VarroaRegisterService $register) {}

    #[OA\Get(path: '/api/v1/beekeeping/hives', operationId: 'beekeepingHivesList', summary: 'List the hives, optionally of one apiary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 200, description: 'Hives')])]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead();
        $apiaryId = $request->query('apiary_id');
        $status = $request->query('status');
        $hives = Hive::query()->with('apiary:id,code,name')
            ->when(is_string($apiaryId) && $apiaryId !== '', static fn ($query) => $query->where('apiary_id', $apiaryId))
            ->when(is_string($status) && $status !== '', static fn ($query) => $query->where('status', $status))
            ->orderBy('code')->get();

        return $this->successResponse(HiveResource::collection($this->withLocks($hives->all())));
    }

    #[OA\Post(path: '/api/v1/beekeeping/hives', operationId: 'beekeepingHivesCreate', summary: 'Register a hive or nucleus', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], responses: [new OA\Response(response: 201, description: 'Hive created')])]
    public function store(SaveHiveRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ((int) ($data['supers_count'] ?? 0) > 0) {
            $this->guard->assertCanPlaceSupers(new Hive(['apiary_id' => $data['apiary_id'], 'code' => $data['code']]));
        }

        $hive = new Hive($data + ['created_by' => $this->userId($request)]);
        $hive->is_nucleus = in_array($data['hive_type'], [HiveType::Nucleus->value, HiveType::MatingNucleus->value], true);
        $hive->save();

        return $this->successResponse(new HiveResource($hive->load('apiary:id,code,name')), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/beekeeping/hives/{id}', operationId: 'beekeepingHivesShow', summary: 'Show a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Hive')])]
    public function show(string $id): JsonResponse
    {
        $this->authorizeRead();
        $hive = Hive::query()->with('apiary:id,code,name')->findOrFail($id);

        return $this->successResponse(new HiveResource($this->withLocks([$hive])[0]));
    }

    #[OA\Get(path: '/api/v1/beekeeping/hives/by-qr/{token}', operationId: 'beekeepingHivesByQr', summary: 'Resolve a scanned hive QR code', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Hive')])]
    public function byQr(string $token): JsonResponse
    {
        $this->authorizeRead();
        $hive = Hive::query()->with('apiary:id,code,name')->where('qr_token', $token)->firstOrFail();

        return $this->successResponse(new HiveResource($this->withLocks([$hive])[0]));
    }

    #[OA\Put(path: '/api/v1/beekeeping/hives/{id}', operationId: 'beekeepingHivesUpdate', summary: 'Update a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Hive updated')])]
    public function update(SaveHiveRequest $request, string $id): JsonResponse
    {
        $hive = Hive::query()->findOrFail($id);
        $data = $request->validated();

        if (isset($data['supers_count']) && (int) $data['supers_count'] > $hive->supers_count) {
            $this->guard->assertCanPlaceSupers($hive);
        }

        $hive->fill($data);

        if (isset($data['hive_type'])) {
            $hive->is_nucleus = in_array($data['hive_type'], [HiveType::Nucleus->value, HiveType::MatingNucleus->value], true);
        }

        $hive->updated_by = $this->userId($request);
        $hive->save();

        return $this->successResponse(new HiveResource($this->withLocks([$hive->load('apiary:id,code,name')])[0]), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/beekeeping/hives/{id}', operationId: 'beekeepingHivesDelete', summary: 'Delete a hive', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Beekeeping'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Hive deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $this->authorizeWrite();
        Hive::query()->findOrFail($id)->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    /**
     * Attaches the withdrawal lock of today to each hive (`withdrawal_lock`: until, product, days left).
     *
     * @param  list<Hive>  $hives
     * @return list<Hive>
     */
    private function withLocks(array $hives): array
    {
        $today = CarbonImmutable::today();
        $locks = [];

        foreach (array_unique(array_map(static fn (Hive $hive): string => $hive->apiary_id, $hives)) as $apiaryId) {
            foreach ($this->register->locksOfApiary($apiaryId, $today) as $lock) {
                $locks[$lock['hive_id']] = ['until' => $lock['until'], 'product' => $lock['product'], 'days_left' => $lock['days_left']];
            }
        }

        foreach ($hives as $hive) {
            $hive->setAttribute('withdrawal_lock', $locks[$hive->id] ?? null);
        }

        return $hives;
    }
}
