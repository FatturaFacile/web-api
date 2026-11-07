<?php

declare(strict_types=1);

namespace Modules\Visitors\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Visitors\Http\Requests\VisitorRequest;
use Modules\Visitors\Http\Resources\VisitorResource;
use Modules\Visitors\Models\Visitor;
use Modules\Visitors\Policies\VisitorsPolicy;
use OpenApi\Attributes as OA;

/**
 * The register of visitors. Reception sees everybody; other members see the visitors of their own visits.
 */
final class VisitorController extends Controller
{
    #[OA\Get(path: '/api/v1/visitors/visitors', operationId: 'listVisitors', description: 'Return the visitors, most recent visit first. Reception sees every visitor, other members only those of the visits they host. Optional filter by CRM contact.', summary: 'List visitors', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [
        new OA\Parameter(name: 'contact_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Visitors', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorCollection')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
    ])]
    public function index(Request $request, VisitorsPolicy $policy): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Visitor::class);

        $visitors = Visitor::query()
            ->when(! $policy->isReception($request->user()), static fn ($query) => $query->whereHas('visits', static fn ($visits) => $visits->where('host_user_id', $request->user()->id)))
            ->when($request->filled('contact_id'), static fn ($query) => $query->where('contact_id', (string) $request->query('contact_id')))
            ->orderByDesc('last_visit_at')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return VisitorResource::collection($visitors);
    }

    #[OA\Post(path: '/api/v1/visitors/visitors', operationId: 'storeVisitor', description: 'Register a visitor. Reception only. Personal data are stored encrypted.', summary: 'Create visitor', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorRequest')), tags: ['Visitors'], responses: [
        new OA\Response(response: 201, description: 'Visitor created', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function store(VisitorRequest $request): JsonResponse
    {
        $this->authorize('receive', Visitor::class);

        return (new VisitorResource(Visitor::query()->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/visitors/visitors/{id}', operationId: 'showVisitor', description: 'Return a visitor.', summary: 'Show visitor', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visitor', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
    ])]
    public function show(string $id): VisitorResource
    {
        $visitor = Visitor::query()->findOrFail($id);
        $this->authorize('view', $visitor);

        return new VisitorResource($visitor);
    }

    #[OA\Put(path: '/api/v1/visitors/visitors/{id}', operationId: 'updateVisitor', description: 'Correct the data of a visitor. Reception only.', summary: 'Update visitor', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorRequest')), tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 200, description: 'Visitor updated', content: new OA\JsonContent(ref: '#/components/schemas/VisitorsVisitorResponse')),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function update(VisitorRequest $request, string $id): VisitorResource
    {
        $visitor = Visitor::query()->findOrFail($id);
        $this->authorize('update', $visitor);
        $visitor->update($request->validated());

        return new VisitorResource($visitor);
    }

    #[OA\Delete(path: '/api/v1/visitors/visitors/{id}', operationId: 'deleteVisitor', description: 'Erase a visitor and all their visits at once, for example on a request of the person. Refused while the visitor is on site. Reception only.', summary: 'Erase visitor', security: [['bearerAuth' => []]], tags: ['Visitors'], parameters: [new OA\Parameter(ref: '#/components/parameters/VisitorsId')], responses: [
        new OA\Response(response: 204, description: 'Visitor erased'),
        new OA\Response(ref: '#/components/responses/VisitorsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/VisitorsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/VisitorsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/VisitorsUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $visitor = Visitor::query()->findOrFail($id);
        $this->authorize('delete', $visitor);
        abort_if($visitor->visits()->where('status', 'checked_in')->exists(), Response::HTTP_UNPROCESSABLE_ENTITY, 'The visitor is on site: check them out first.');
        $visitor->delete();

        return response()->noContent();
    }
}
