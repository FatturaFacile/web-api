<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\FiduciaryPortal\Http\Requests\ResolveFiduciaryEntryNoteRequest;
use Modules\FiduciaryPortal\Http\Requests\StoreFiduciaryEntryNoteRequest;
use Modules\FiduciaryPortal\Policies\FiduciaryPortalPolicy;
use Modules\FiduciaryPortal\Services\FiduciaryEntryNoteService;
use OpenApi\Attributes as OA;

final class FiduciaryEntryNoteController extends Controller
{
    use ApiResponse;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiduciaryEntryNoteService $notes,
    ) {}

    #[OA\Get(
        path: '/api/v1/fiduciary-portal/notes',
        operationId: 'fiduciaryPortalNotesIndex',
        summary: 'List anchored internal notes and communication threads',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Entry notes list')]
    )]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_VIEW);
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $request->user()?->tenant_id);

        return $this->successResponse($this->notes->listNotes(
            tenantId: $tenantId,
            journalEntryId: $request->query('journal_entry_id') ? (string) $request->query('journal_entry_id') : null,
            status: $request->query('status') ? (string) $request->query('status') : null,
            search: $request->query('search') ? (string) $request->query('search') : null,
        ));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/notes',
        operationId: 'fiduciaryPortalNotesStore',
        summary: 'Create an anchored note or threaded reply on a journal entry',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 201, description: 'Note created')]
    )]
    public function store(StoreFiduciaryEntryNoteRequest $request): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_NOTES);

        /** @var User $actor */
        $actor = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $actor->tenant_id);
        $validated = $request->validated();

        $note = $this->notes->createNote(
            tenantId: $tenantId,
            journalEntryId: (string) $validated['journal_entry_id'],
            body: (string) $validated['body'],
            actor: $actor,
            parentNoteId: isset($validated['parent_note_id']) ? (string) $validated['parent_note_id'] : null,
        );

        return $this->createdResponse($this->notes->formatNote($note), __('fiduciary_portal.note_created'));
    }

    #[OA\Post(
        path: '/api/v1/fiduciary-portal/notes/{id}/resolve',
        operationId: 'fiduciaryPortalNotesResolve',
        summary: 'Resolve or reopen an anchored entry note thread',
        security: self::SECURITY,
        tags: ['Fiduciary Portal'],
        responses: [new OA\Response(response: 200, description: 'Note status updated')]
    )]
    public function resolve(ResolveFiduciaryEntryNoteRequest $request, string $id): JsonResponse
    {
        Gate::authorize(FiduciaryPortalPolicy::ABILITY_NOTES);

        /** @var User $actor */
        $actor = $request->user();
        $tenantId = (string) ($this->tenantContext->getTenantId() ?? $actor->tenant_id);
        $validated = $request->validated();

        $note = $this->notes->resolveNote(
            tenantId: $tenantId,
            noteId: $id,
            resolved: (bool) $validated['resolved'],
            comment: isset($validated['resolution_comment']) ? (string) $validated['resolution_comment'] : null,
            actor: $actor,
        );

        return $this->successResponse($this->notes->formatNote($note), __('fiduciary_portal.note_updated'));
    }
}
