<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Visitors\Http\Requests\KioskRequest;
use Modules\Visitors\Http\Resources\KioskResource;
use Modules\Visitors\Models\VisitorKiosk;
use Modules\Visitors\Services\VisitorKioskService;
use OpenApi\Attributes as OA;

/**
 * Registration of the reception kiosks and of their secrets. Owner and administrators only.
 */
final class KioskController extends Controller
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly VisitorKioskService $kiosks) {}

    /**
     * List kiosks.
     */
    #[OA\Get(path: '/api/v1/visitors/kiosks', operationId: 'listVisitorKiosks', description: 'Return the reception kiosks. Their secrets are never shown again.', summary: 'List kiosks', security: [['bearerAuth' => []]], tags: ['Visitors'], responses: [
        new OA\Response(response: 200, description: 'Kiosks', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskCollection')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
    ])]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('manageKiosks', VisitorKiosk::class);

        return KioskResource::collection(VisitorKiosk::query()->orderBy('name')->get());
    }

    /**
     * Register kiosk.
     */
    #[OA\Post(path: '/api/v1/visitors/kiosks', operationId: 'storeVisitorKiosk', description: 'Register a kiosk. The answer carries its secret, once: only its hash is stored.', summary: 'Register kiosk', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskRequest')), tags: ['Visitors'], responses: [
        new OA\Response(response: 201, description: 'Kiosk registered, with its secret', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function store(KioskRequest $request): JsonResponse
    {
        $this->authorize('manageKiosks', VisitorKiosk::class);
        $registered = $this->kiosks->register((string) $request->validated('name'));

        return (new KioskResource($registered['kiosk'], $registered['secret']))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Rotate kiosk secret.
     */
    #[OA\Post(path: '/api/v1/visitors/kiosks/{id}/rotate-secret', operationId: 'rotateVisitorKioskSecret', description: 'Replace the secret of a kiosk; the old one stops working at once. The answer carries the new secret, once.', summary: 'Rotate kiosk secret', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'New secret', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsKioskResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
    ])]
    public function rotate(string $id): KioskResource
    {
        $kiosk = VisitorKiosk::query()->findOrFail($id);
        $this->authorize('manageKiosks', $kiosk);

        return new KioskResource($kiosk, $this->kiosks->rotateSecret($kiosk));
    }

    /**
     * Retire kiosk.
     */
    #[OA\Delete(path: '/api/v1/visitors/kiosks/{id}', operationId: 'deleteVisitorKiosk', description: 'Retire a kiosk: it can no longer authenticate.', summary: 'Retire kiosk', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 204, description: 'Kiosk retired'),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $kiosk = VisitorKiosk::query()->findOrFail($id);
        $this->authorize('manageKiosks', $kiosk);
        $kiosk->update(['is_active' => false]);
        $kiosk->delete();

        return response()->noContent();
    }
}
