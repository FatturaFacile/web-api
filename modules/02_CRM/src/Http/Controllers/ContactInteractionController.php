<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Contracts\ActivityServiceInterface;
use Modules\CRM\DTOs\CreateActivityDTO;
use Modules\CRM\Http\Resources\ActivityResource;
use Modules\CRM\Models\Activity;
use OpenApi\Attributes as OA;

/**
 * Interaction history (calls, emails, notes, meetings) of a customer registry entry. Web and mobile read and write the
 * same store; `updated_since` gives the mobile app an incremental sync cursor.
 */
final class ContactInteractionController extends Controller
{
    private const TYPES = ['call', 'email', 'note', 'meeting'];

    public function __construct(
        private readonly ActivityServiceInterface $activityService,
        private readonly TenantContext $tenantContext
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/contacts/{id}/interactions',
        operationId: 'listCrmContactInteractions',
        description: 'Interaction history (calls, emails, notes, meetings) of a customer registry entry, newest first. updated_since gives mobile clients an incremental sync cursor; meta.server_time is the next cursor.',
        summary: 'List contact interactions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Contact Interactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contact', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Restrict to one interaction type', schema: new OA\Schema(type: 'string', enum: ['call', 'email', 'note', 'meeting'])),
            new OA\Parameter(name: 'updated_since', in: 'query', required: false, description: 'Only interactions changed after this ISO 8601 date', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size, 1 to 100 (default 25)', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated interactions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'server_time', type: 'string', format: 'date-time')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorizeFor($request, 'view', $contact);

        $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', self::TYPES)],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Activity::where('tenant_id', $this->tenantContext->getTenant()->id)
            ->where('related_type', 'contact')
            ->where('related_id', $contact->id)
            ->whereIn('type', self::TYPES);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('updated_since')) {
            $query->where('updated_at', '>', $request->query('updated_since'));
        }

        $page = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => ActivityResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/crm/contacts/{id}/interactions',
        operationId: 'createCrmContactInteraction',
        description: 'Records an interaction that already happened (call, email, note or meeting) on a contact; it is stored as a completed activity at occurred_at.',
        summary: 'Log contact interaction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Contact Interactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contact', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['type', 'subject'], properties: [new OA\Property(property: 'type', type: 'string', enum: ['call', 'email', 'note', 'meeting']), new OA\Property(property: 'subject', type: 'string', maxLength: 200), new OA\Property(property: 'notes', type: 'string', nullable: true), new OA\Property(property: 'outcome', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'duration_minutes', type: 'integer', minimum: 1, nullable: true), new OA\Property(property: 'location', type: 'string', maxLength: 255, nullable: true), new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Interaction logged', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorizeFor($request, 'update', $contact);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'subject' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
            'outcome' => ['nullable', 'string', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'location' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()->id ?? 'system');

        $activity = $this->activityService->logActivity(new CreateActivityDTO(
            tenantId: (string) $tenant->id,
            companyId: (string) $tenant->id,
            subject: $data['subject'],
            type: $data['type'],
            relatedType: 'contact',
            relatedId: $contact->id,
            durationMinutes: $data['duration_minutes'] ?? null,
            location: $data['location'] ?? null,
            notes: $data['notes'] ?? null,
        ), $actorId);

        // An interaction is something that already happened: record it as completed at the given moment.
        $activity = $this->activityService->completeActivity($activity, $data['outcome'] ?? null, $actorId);

        if (! empty($data['occurred_at'])) {
            $activity->update(['completed_at' => $data['occurred_at']]);
        }

        return response()->json(['data' => new ActivityResource($activity->refresh())], 201);
    }

    private function authorizeFor(Request $request, string $ability, Contact $contact): void
    {
        abort_unless($request->user()?->can($ability, $contact), 403);
    }
}
