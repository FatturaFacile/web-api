<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\GreenCare\Application\Services\ContractWriter;
use Modules\GreenCare\Http\Controllers\Concerns\InteractsWithGreenCare;
use Modules\GreenCare\Http\Requests\SaveSiteRequest;
use Modules\GreenCare\Http\Resources\SiteResource;
use Modules\GreenCare\Models\GreenSpaceSite;
use OpenApi\Attributes as OA;

/**
 * Green space sites: gardens, lawns and condominium green areas with geometry, access data and botanical inventory.
 */
final class SiteController extends Controller
{
    use ApiResponse;
    use InteractsWithGreenCare;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/green-care/sites', operationId: 'greenCareSitesIndex', summary: 'Green space sites', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Sites')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', GreenSpaceSite::class);

        $search = trim((string) request()->query('search', ''));
        $page = GreenSpaceSite::query()
            ->with(['customer', 'condominiumAdministrator'])
            ->when($search !== '', static fn ($query) => $query->where(static fn ($inner) => $inner
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(address) LIKE ?', ['%'.mb_strtolower($search).'%'])))
            ->when(request()->query('customer_id') !== null, static fn ($query) => $query->where('customer_id', (string) request()->query('customer_id')))
            ->when(request()->query('is_active') !== null, static fn ($query) => $query->where('is_active', filter_var(request()->query('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->paginate($this->perPage());

        return $this->paged($page, SiteResource::class);
    }

    #[OA\Post(path: '/api/v1/green-care/sites', operationId: 'greenCareSitesStore', summary: 'Create a site', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 201, description: 'Site')])]
    public function store(SaveSiteRequest $request, ContractWriter $writer): JsonResponse
    {
        Gate::authorize('create', GreenSpaceSite::class);
        $data = $request->validated();
        $data['jurisdiction'] ??= $writer->tenantJurisdiction($this->tenantId());
        $site = GreenSpaceSite::query()->create($this->attributes($data) + ['tenant_id' => $this->tenantId()]);

        return $this->createdResponse(new SiteResource($site->load(['customer', 'condominiumAdministrator'])), $this->message('site_saved'));
    }

    #[OA\Get(path: '/api/v1/green-care/sites/{id}', operationId: 'greenCareSitesShow', summary: 'One site', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Site')])]
    public function show(string $id): JsonResponse
    {
        $site = GreenSpaceSite::query()->with(['customer', 'condominiumAdministrator'])->findOrFail($id);
        Gate::authorize('view', $site);

        return $this->successResponse(new SiteResource($site), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/green-care/sites/{id}', operationId: 'greenCareSitesUpdate', summary: 'Change a site', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Site')])]
    public function update(SaveSiteRequest $request, string $id): JsonResponse
    {
        $site = GreenSpaceSite::query()->findOrFail($id);
        Gate::authorize('update', $site);
        $site->update($this->attributes($request->validated()));

        return $this->successResponse(new SiteResource($site->refresh()->load(['customer', 'condominiumAdministrator'])), $this->message('site_saved'));
    }

    #[OA\Delete(path: '/api/v1/green-care/sites/{id}', operationId: 'greenCareSitesDestroy', summary: 'Delete a site', security: self::SECURITY, tags: ['Green care'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $site = GreenSpaceSite::query()->findOrFail($id);
        Gate::authorize('delete', $site);
        $site->delete();

        return $this->successResponse(null, $this->message('site_deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = $data;

        foreach (['lawn_area_sqm', 'hedge_length_meters', 'tree_count'] as $numeric) {
            if (array_key_exists($numeric, $attributes)) {
                $attributes[$numeric] = $attributes[$numeric] ?? 0;
            }
        }

        return $attributes;
    }
}
