<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Http\Controllers\Concerns\InteractsWithSupplierPortal;
use Modules\SupplierPortal\Http\Requests\InviteSupplierRequest;
use Modules\SupplierPortal\Http\Requests\OnboardingActionRequest;
use Modules\SupplierPortal\Http\Resources\DocumentRequirementResource;
use Modules\SupplierPortal\Http\Resources\DocumentSubmissionResource;
use Modules\SupplierPortal\Http\Resources\OnboardingResource;
use Modules\SupplierPortal\Http\Resources\QualificationAssessmentResource;
use Modules\SupplierPortal\Http\Resources\RiskProfileResource;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Models\SupplierRiskProfile;
use Modules\SupplierPortal\Services\SupplierDocumentService;
use Modules\SupplierPortal\Services\SupplierOnboardingService;
use Modules\SupplierPortal\Services\SupplierPortalAccessService;
use Modules\SupplierPortal\Services\SupplierQualificationScoringService;
use OpenApi\Attributes as OA;

/**
 * The onboarding workflow of the suppliers: invitation, review steps and the decision.
 */
final class OnboardingController extends Controller
{
    use InteractsWithSupplierPortal;

    public function __construct(
        private readonly SupplierOnboardingService $onboardings,
        private readonly SupplierDocumentService $documents,
        private readonly SupplierQualificationScoringService $scoring,
        private readonly SupplierPortalAccessService $access,
    ) {}

    #[OA\Get(path: '/api/v1/supplier-portal/onboardings', operationId: 'listSupplierOnboardings', description: 'Paginated onboardings of the company, newest first. Filters: status, country, search (supplier name or VAT number).', summary: 'List supplier onboardings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['invited', 'registration_submitted', 'documents_required', 'under_review', 'approved', 'rejected', 'blocked'])),
        new OA\Parameter(name: 'country', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH'])),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/SupplierPortalPerPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Onboardings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SpOnboarding'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplierOnboarding::class);

        $request->validate(['status' => ['nullable', Rule::in(OnboardingStatus::values())]]);
        $search = trim((string) $request->query('search', ''));

        return OnboardingResource::collection(
            SupplierOnboarding::query()
                ->with('supplier')
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('country'), static fn ($query) => $query->where('country', strtoupper((string) $request->query('country'))))
                ->when($search !== '', static fn ($query) => $query->whereHas('supplier', static fn ($supplier) => $supplier->where('company_name', 'like', "%{$search}%")->orWhere('vat_number', 'like', "%{$search}%")))
                ->orderByDesc('created_at')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings', operationId: 'inviteSupplier', description: 'Invites a supplier of the procurement module to onboard: an onboarding is opened in status invited and the personal portal link is mailed. One onboarding per supplier.', summary: 'Invite a supplier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpInviteSupplierRequest')), tags: ['Supplier Portal'], responses: [
        new OA\Response(response: 201, description: 'Supplier invited', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function store(InviteSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierOnboarding::class);
        $supplier = $this->supplier((string) $request->validated('supplier_id'));

        $onboarding = $this->onboardings->invite($this->tenantId(), $supplier, $request->validated('invitation_email'), (string) $request->validated('country'), $this->userId());

        return (new OnboardingResource($onboarding->load('supplier')))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/supplier-portal/onboardings/{id}', operationId: 'showSupplierOnboarding', description: 'The onboarding with the checklist of documents required for its country (the latest document of the supplier for each), what blocks the approval, the latest qualification and the risk flag.', summary: 'Show a supplier onboarding', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Onboarding detail', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboardingDetail')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $onboarding = $this->find($id);
        $this->authorize('view', $onboarding);

        $latest = SupplierDocumentSubmission::query()
            ->with(['requirement', 'supplier'])
            ->where('supplier_id', $onboarding->supplier_id)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('requirement_id')
            ->map(static fn ($group) => $group->first());

        $requirements = $this->documents->requirementsFor((string) $onboarding->country)->map(static fn ($requirement): array => [
            ...(new DocumentRequirementResource($requirement))->resolve(),
            'submission' => $latest->has($requirement->id) ? (new DocumentSubmissionResource($latest->get($requirement->id)))->resolve() : null,
        ]);

        $assessment = $this->scoring->latestCompleted((string) $onboarding->supplier_id);
        $risk = SupplierRiskProfile::query()->where('supplier_id', $onboarding->supplier_id)->first();

        return response()->json(['data' => [
            'onboarding' => (new OnboardingResource($onboarding))->resolve(),
            'requirements' => $requirements->values()->all(),
            'blockers' => $this->onboardings->approvalBlockers($onboarding),
            'assessment' => $assessment === null ? null : (new QualificationAssessmentResource($assessment->load('scores.criterion')))->resolve(),
            'risk' => $risk === null ? null : (new RiskProfileResource($risk))->resolve(),
        ]]);
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/portal-access', operationId: 'issueSupplierPortalAccess', description: 'Revokes the earlier links of the supplier and mails a new personal portal link.', summary: 'Send a new portal link', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Link sent', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function portalAccess(string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('update', $onboarding);
        $this->access->issueAccess($onboarding);

        return new OnboardingResource($onboarding->load('supplier'));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/request-documents', operationId: 'requestSupplierDocuments', description: 'The reviewer asks the supplier for (more) documents; the notes are shown to the supplier. From registration submitted or under review.', summary: 'Ask for documents', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SpOnboardingActionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Documents requested', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function requestDocuments(OnboardingActionRequest $request, string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('update', $onboarding);

        return $this->resource($this->onboardings->requestDocuments($onboarding, $request->validated('notes')));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/start-review', operationId: 'startSupplierReview', description: 'Starts the review. Also restarts it after a rejection or a block (the supplier goes back to in qualification).', summary: 'Start the review', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Review started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function startReview(string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('update', $onboarding);

        return $this->resource($this->onboardings->startReview($onboarding));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/approve', operationId: 'approveSupplierOnboarding', description: 'Approves a supplier under review and qualifies it in the procurement module (conditionally for a band B assessment). Refused while a mandatory document is not accepted and valid, the qualification is missing or not passed, a check failed or the risk is critical. Owners, administrators, procurement managers or supplier_portal.approve.', summary: 'Approve a supplier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SpOnboardingActionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Supplier approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function approve(OnboardingActionRequest $request, string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('decide', $onboarding);

        return $this->resource($this->onboardings->approve($onboarding, $request->validated('notes'), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/reject', operationId: 'rejectSupplierOnboarding', description: 'Rejects the onboarding with a reason; the supplier is disqualified in the procurement module. Same roles as the approval.', summary: 'Reject a supplier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpOnboardingActionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Supplier rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function reject(OnboardingActionRequest $request, string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('decide', $onboarding);

        return $this->resource($this->onboardings->reject($onboarding, (string) $request->validated('reason'), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/supplier-portal/onboardings/{id}/block', operationId: 'blockSupplierOnboarding', description: 'Blocks an approved supplier with a reason: it is disqualified and loses the approved vendor flag, so no purchase order can be issued to it. Same roles as the approval.', summary: 'Block a supplier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpOnboardingActionRequest')), tags: ['Supplier Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Supplier blocked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpOnboarding')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function block(OnboardingActionRequest $request, string $id): OnboardingResource
    {
        $onboarding = $this->find($id);
        $this->authorize('decide', $onboarding);

        return $this->resource($this->onboardings->block($onboarding, (string) $request->validated('reason'), $this->userId()));
    }

    private function find(string $id): SupplierOnboarding
    {
        return SupplierOnboarding::query()->with('supplier')->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function resource(SupplierOnboarding $onboarding): OnboardingResource
    {
        return new OnboardingResource($onboarding->load('supplier'));
    }
}
