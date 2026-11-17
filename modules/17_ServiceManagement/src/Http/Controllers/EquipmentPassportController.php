<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Contracts\EquipmentPassportServiceInterface;
use Modules\ServiceManagement\DTOs\AddWarrantyExtensionDTO;
use Modules\ServiceManagement\DTOs\RegisterEquipmentPassportDTO;
use Modules\ServiceManagement\DTOs\UpdateEquipmentPassportDTO;
use Modules\ServiceManagement\Exceptions\WarrantyRmaException;
use Modules\ServiceManagement\Http\Requests\StoreEquipmentPassportRequest;
use Modules\ServiceManagement\Http\Requests\StoreWarrantyExtensionRequest;
use Modules\ServiceManagement\Http\Requests\UpdateEquipmentPassportRequest;
use Modules\ServiceManagement\Http\Resources\EquipmentComponentResource;
use Modules\ServiceManagement\Http\Resources\EquipmentPassportResource;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;

final class EquipmentPassportController extends Controller
{
    public function __construct(
        private readonly EquipmentPassportServiceInterface $passportService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerializedEquipmentPassport::class);

        $today = now()->toDateString();

        $passports = SerializedEquipmentPassport::query()
            ->with(['customer', 'components', 'extensions'])
            ->when($request->filled('serial_number'), fn ($query) => $query->where('serial_number', 'like', $request->string('serial_number')->trim()->value().'%'))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('current_owner_customer_id', $request->query('customer_id')))
            ->when($request->filled('jurisdiction'), fn ($query) => $query->where('jurisdiction', strtoupper((string) $request->query('jurisdiction'))))
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->query('product_id')))
            ->when($request->filled('asset_status'), fn ($query) => $query->where('asset_status', $request->query('asset_status')))
            ->when($request->filled('expiring_within_days'), fn ($query) => $query
                ->whereDate('warranty_expiry_date', '>=', $today)
                ->whereDate('warranty_expiry_date', '<=', now()->addDays((int) $request->query('expiring_within_days'))->toDateString()))
            ->when($request->query('warranty') === 'in', fn ($query) => $query->whereDate('warranty_expiry_date', '>=', $today))
            ->when($request->query('warranty') === 'out', fn ($query) => $query->whereDate('warranty_expiry_date', '<', $today))
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => EquipmentPassportResource::collection($passports->getCollection()),
            'meta' => [
                'current_page' => $passports->currentPage(),
                'last_page' => $passports->lastPage(),
                'per_page' => $passports->perPage(),
                'total' => $passports->total(),
            ],
        ]);
    }

    public function store(StoreEquipmentPassportRequest $request): JsonResponse
    {
        $this->authorize('create', SerializedEquipmentPassport::class);
        $tenant = $this->tenant();

        $passport = $this->passportService->register(
            RegisterEquipmentPassportDTO::fromValidated($tenant->id, $tenant->id, $request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Equipment passport registered successfully',
            'data' => new EquipmentPassportResource($passport),
        ], 201);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerializedEquipmentPassport::class);

        $serial = trim((string) $request->query('serial_number', ''));
        if ($serial === '') {
            throw WarrantyRmaException::validation('serial_number', 'The serial number field is required.');
        }

        $productId = $request->filled('product_id') ? (string) $request->query('product_id') : null;
        $result = $this->passportService->lookup($serial, $productId);

        return response()->json([
            'data' => [
                'passport' => new EquipmentPassportResource($result['passport']),
                'warranty' => $result['warranty'],
                'rma_history' => $result['rma_history'],
                'stock_hint' => $result['stock_hint'],
            ],
        ]);
    }

    public function show(SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('view', $passport);

        return response()->json([
            'data' => new EquipmentPassportResource($passport->load(['customer', 'components', 'extensions'])),
        ]);
    }

    public function update(UpdateEquipmentPassportRequest $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('update', $passport);

        $updated = $this->passportService->update(
            $passport,
            UpdateEquipmentPassportDTO::fromValidated($request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Equipment passport updated successfully',
            'data' => new EquipmentPassportResource($updated),
        ]);
    }

    public function storeExtension(StoreWarrantyExtensionRequest $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('addExtension', $passport);

        $updated = $this->passportService->addExtension(
            $passport,
            AddWarrantyExtensionDTO::fromValidated($request->validated()),
            $request->user()?->id,
        );

        return response()->json([
            'message' => 'Warranty extension added successfully',
            'data' => new EquipmentPassportResource($updated),
        ], 201);
    }

    public function genealogy(Request $request, SerializedEquipmentPassport $passport): JsonResponse
    {
        $this->authorize('view', $passport);

        $genealogy = $this->passportService->genealogy($passport);

        return response()->json([
            'data' => [
                'as_built' => EquipmentComponentResource::collection(collect($genealogy['as_built']))->resolve($request),
                'as_maintained' => EquipmentComponentResource::collection(collect($genealogy['as_maintained']))->resolve($request),
                'history' => $genealogy['history'],
            ],
        ]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, 403, 'No active tenant.');

        return $tenant;
    }
}
