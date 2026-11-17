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
        $actorId = (string) ($request->user()?->id ?? 'system');

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
