<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\CorporateConventions\Application\Commands\RegisterConventionFamilyMemberCommand;
use Modules\CorporateConventions\Application\Commands\RegisterConventionFamilyMemberHandler;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryByCardOrHashCommand;
use Modules\CorporateConventions\Application\Commands\VerifyBeneficiaryByCardOrHashHandler;
use Modules\CorporateConventions\Domain\Enums\Relationship;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\FamilyMemberRequest;
use Modules\CorporateConventions\Http\Requests\ListFiltersRequest;
use Modules\CorporateConventions\Http\Requests\VerifyIdentifierRequest;
use Modules\CorporateConventions\Http\Resources\EnrollmentResource;
use Modules\CorporateConventions\Models\ConventionBeneficiaryEnrollment;
use Modules\CorporateConventions\Services\EnrollmentService;
use Modules\CorporateConventions\Services\WalletPassService;
use OpenApi\Attributes as OA;

/**
 * Beneficiaries of the conventions: the list, the instant verification of a pass at the desk or the till, family members and revocation.
 */
final class EnrollmentController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly EnrollmentService $enrollments) {}

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/enrollments', operationId: 'corporateConventionsEnrollmentsList', summary: 'List the beneficiaries of the conventions', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Enrolments, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', ConventionBeneficiaryEnrollment::class);

        $page = ConventionBeneficiaryEnrollment::query()
            ->with('convention:id,name,currency,annual_spending_cap_per_beneficiary,employer_subsidy_annual_cap')
            ->when($request->input('convention_id'), fn (Builder $query, string $id) => $query->where('convention_id', $id))
            ->when($request->input('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->input('q'), function (Builder $query, string $term): void {
                $like = '%'.mb_strtolower($term).'%';
                $query->where(fn (Builder $inner) => $inner->whereRaw('LOWER(full_name) LIKE ?', [$like])->orWhereRaw('LOWER(verified_email) LIKE ?', [$like]));
            })
            ->orderByDesc('verified_at')
            ->paginate($this->perPage());

        return $this->paged($page, EnrollmentResource::class);
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/enrollments/{id}', operationId: 'corporateConventionsEnrollmentShow', summary: 'Show a beneficiary with the family members and the cap consumption', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Enrolment')])]
    public function show(string $id): JsonResponse
    {
        $enrollment = ConventionBeneficiaryEnrollment::query()->with(['convention', 'familyMembers'])->findOrFail($id);
        Gate::authorize('view', $enrollment);

        return $this->successResponse(new EnrollmentResource($enrollment), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/enrollments/verify', operationId: 'corporateConventionsVerifyIdentifier', summary: 'Verify a pass by QR payload, card, matricola hash or e-mail, enrolling a listed holder when a name is given', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Eligible beneficiary with the remaining caps'), new OA\Response(response: 201, description: 'Beneficiary enrolled')])]
    public function verify(VerifyIdentifierRequest $request, VerifyBeneficiaryByCardOrHashHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', ConventionBeneficiaryEnrollment::class);
        $data = $request->validated();
        $result = $handler->handle(new VerifyBeneficiaryByCardOrHashCommand($data['identifier'], $data['convention_id'] ?? null, $data['full_name'] ?? null, $data['email'] ?? null, $this->userId()));

        return $this->successResponse(new EnrollmentResource($result['enrollment']->load(['convention', 'familyMembers'])), $this->message('verified'), $result['created'] ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/enrollments/{id}/family-members', operationId: 'corporateConventionsAddFamilyMember', summary: 'Register a first-degree family member of an employee', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Family member enrolled with a linked pass')])]
    public function addFamilyMember(FamilyMemberRequest $request, string $id, RegisterConventionFamilyMemberHandler $handler): JsonResponse
    {
        $parent = ConventionBeneficiaryEnrollment::query()->findOrFail($id);
        Gate::authorize('update', $parent);
        $member = $handler->handle(new RegisterConventionFamilyMemberCommand($id, (string) $request->validated('full_name'), Relationship::from((string) $request->validated('relationship')), $this->userId()));

        return $this->successResponse(new EnrollmentResource($member->load('convention')), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/enrollments/{id}/revoke', operationId: 'corporateConventionsRevokeEnrollment', summary: 'Revoke a beneficiary and their family members', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Enrolment revoked')])]
    public function revoke(string $id): JsonResponse
    {
        $enrollment = ConventionBeneficiaryEnrollment::query()->findOrFail($id);
        Gate::authorize('update', $enrollment);

        return $this->successResponse(new EnrollmentResource($this->enrollments->revoke($enrollment, $this->userId())->load('convention')), $this->message('revoked'));
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/enrollments/{id}/qr', operationId: 'corporateConventionsEnrollmentQr', summary: 'QR code (SVG) of the pass of a beneficiary', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'SVG image')])]
    public function qr(string $id, WalletPassService $wallets): Response
    {
        $enrollment = ConventionBeneficiaryEnrollment::query()->findOrFail($id);
        Gate::authorize('view', $enrollment);

        return response($wallets->qrSvg($enrollment->qr_payload), 200, ['Content-Type' => 'image/svg+xml']);
    }
}
