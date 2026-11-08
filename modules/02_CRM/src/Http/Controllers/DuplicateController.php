<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\MergeContactsRequest;
use Modules\CRM\Http\Resources\ContactMasterDataResource;
use Modules\CRM\Http\Resources\LeadResource;
use Modules\CRM\Models\ContactMerge;
use Modules\CRM\Models\Lead;
use Modules\CRM\Services\PartyMaster\ContactDuplicateFinder;
use Modules\CRM\Services\PartyMaster\ContactMergeService;
use OpenApi\Attributes as OA;

/**
 * Duplicate detection on contacts and leads, and the audited merge of duplicate contacts.
 */
final class DuplicateController extends Controller
{
    public function __construct(
        private readonly ContactDuplicateFinder $finder,
        private readonly ContactMergeService $mergeService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Groups of duplicates, or the records matching a candidate when keys are given.
     */
    #[OA\Get(
        path: '/api/v1/crm/duplicates',
        operationId: 'listCrmDuplicates',
        description: 'Without keys: the groups of duplicate contacts (normalised VAT number, fiscal code, email, phone) or leads (email, phone) of the active tenant, with the keys that matched. With vat_number, fiscal_code, email or phone: the records matching that candidate, to check before creating one. Supersedes GET /api/v1/crm/leads/duplicates.',
        summary: 'Detect duplicates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Duplicates'],
        parameters: [
            new OA\Parameter(name: 'entity', in: 'query', required: false, description: 'Records to search', schema: new OA\Schema(type: 'string', default: 'contacts', enum: ['contacts', 'leads'])),
            new OA\Parameter(name: 'vat_number', in: 'query', required: false, description: 'Candidate VAT number (contacts)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'fiscal_code', in: 'query', required: false, description: 'Candidate fiscal code (contacts)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'email', in: 'query', required: false, description: 'Candidate email', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'phone', in: 'query', required: false, description: 'Candidate phone', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Duplicate groups or candidate matches', content: new OA\JsonContent(properties: [new OA\Property(property: 'entity', type: 'string'), new OA\Property(property: 'mode', type: 'string', enum: ['groups', 'matches']), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMDuplicateGroup'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity' => ['nullable', 'in:contacts,leads'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'fiscal_code' => ['nullable', 'string', 'max:16'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);

        $tenantId = (string) $this->tenantContext->getTenantId();
        $entity = $validated['entity'] ?? 'contacts';
        $candidate = array_filter(array_intersect_key($validated, array_flip(['vat_number', 'fiscal_code', 'email', 'phone'])));

        if ($candidate !== []) {
            $matches = $entity === 'leads' ? $this->finder->leadMatches($tenantId, $candidate) : $this->finder->contactMatches($tenantId, $candidate);

            return response()->json([
                'entity' => $entity,
                'mode' => 'matches',
                'data' => array_map(fn (array $match): array => ['matched_on' => $match['matched_on'], 'records' => [$this->present($match['record'])]], $matches),
            ]);
        }

        $groups = $entity === 'leads' ? $this->finder->leadGroups($tenantId) : $this->finder->contactGroups($tenantId);

        return response()->json([
            'entity' => $entity,
            'mode' => 'groups',
            'data' => array_map(fn (array $group): array => [
                'matched_on' => $group['matched_on'],
                'records' => array_map(fn (Contact|Lead $record): array => $this->present($record), $group['records']),
            ], $groups),
        ]);
    }

    /**
     * Merges a duplicate contact into a surviving one.
     */
    #[OA\Post(
        path: '/api/v1/crm/contact-merges',
        operationId: 'mergeCrmContacts',
        description: 'Merges merged_id into survivor_id in one transaction: every reference to the merged contact (invoices, quotes, orders, CRM accounts, opportunities, campaign members, consents, activities...) is repointed to the survivor, empty master data of the survivor is filled from the merged contact (fill_missing, default true), and the merged contact is soft-deleted. Issued invoices are immutable and keep the contact they were issued to (reported as retained). The merge is recorded in the merge history and the audit ledger.',
        summary: 'Merge duplicate contacts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Duplicates'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMMergeContactsRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Contacts merged', content: new OA\JsonContent(ref: '#/components/schemas/CRMContactMerge')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A reference cannot be moved; nothing was changed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function merge(MergeContactsRequest $request): JsonResponse
    {
        try {
            $merge = $this->mergeService->merge(
                (string) $this->tenantContext->getTenantId(),
                (string) $request->input('survivor_id'),
                (string) $request->input('merged_id'),
                $request->user()?->id !== null ? (string) $request->user()->id : null,
                $request->boolean('fill_missing', true),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($this->presentMerge($merge), 201);
    }

    /**
     * The merge history of the tenant.
     */
    #[OA\Get(
        path: '/api/v1/crm/contact-merges',
        operationId: 'listCrmContactMerges',
        description: 'Lists the contact merges of the active tenant, newest first, with the repointed references and the snapshot of each merged contact.',
        summary: 'List contact merges',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Duplicates'],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1))],
        responses: [
            new OA\Response(response: 200, description: 'Merge history', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMContactMerge')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function merges(): JsonResponse
    {
        $merges = ContactMerge::where('tenant_id', $this->tenantContext->getTenantId())->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => $merges->getCollection()->map(fn (ContactMerge $merge): array => $this->presentMerge($merge))->values(),
            'meta' => ['current_page' => $merges->currentPage(), 'total' => $merges->total()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Contact|Lead $record): array
    {
        return $record instanceof Contact
            ? (new ContactMasterDataResource($record))->resolve()
            : (new LeadResource($record))->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMerge(ContactMerge $merge): array
    {
        return [
            'id' => (string) $merge->id,
            'survivor_contact_id' => $merge->survivor_contact_id,
            'merged_contact_id' => $merge->merged_contact_id,
            'repointed' => $merge->repointed ?? [],
            'retained' => $merge->retained ?? [],
            'filled_fields' => $merge->filled_fields ?? [],
            'merged_snapshot' => $merge->merged_snapshot ?? [],
            'merged_by_user_id' => $merge->merged_by_user_id,
            'created_at' => $merge->created_at?->toIso8601String(),
        ];
    }
}
