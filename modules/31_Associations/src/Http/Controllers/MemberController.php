<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Associations\Http\Requests\AssignRegistryTypeRequest;
use Modules\Associations\Http\Requests\AssignSocialRoleRequest;
use Modules\Associations\Http\Requests\SaveMemberRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Http\Resources\MemberResource;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\RegistryExportService;
use Modules\Associations\Services\RegistryService;
use Modules\Associations\Support\AssociationCapability;

/**
 * The registry: people, their types over the years and their offices.
 */
final class MemberController extends AssociationController
{
    public function __construct(
        private readonly RegistryService $registry,
        private readonly RegistryExportService $exports,
    ) {}

    #[AssociationGet('/api/v1/associations/members', 'listAssociationMembers', 'List the people of the registry', query: ['status', 'registry_type_id', 'social_role_id', 'year_id', 'membership_status', 'search'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Member::class);

        return MemberResource::collection($this->filtered($request)->with('contact')->gridPaginate($this->perPage($request)));
    }

    #[AssociationGet('/api/v1/associations/members/export', 'exportAssociationMembers', 'Export the people of the registry as CSV, without health data', query: ['status', 'registry_type_id', 'social_role_id', 'year_id', 'membership_status', 'search'], file: true)]
    public function export(Request $request): Response
    {
        $this->allow(AssociationCapability::Manage);

        return response($this->exports->csv($this->filtered($request)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="registry.csv"',
        ]);
    }

    #[AssociationPost('/api/v1/associations/members', 'createAssociationMember', 'Register a person', 'AssociationMemberRequest')]
    public function store(SaveMemberRequest $request): MemberResource
    {
        $this->authorize('create', Member::class);

        return new MemberResource($this->registry->create($this->withoutHealthUnlessAllowed($request->validated())));
    }

    #[AssociationGet('/api/v1/associations/members/{id}', 'showAssociationMember', 'Show a person with the history per year')]
    public function show(string $id): MemberResource
    {
        $member = Member::query()->with(['contact', 'registryAssignments.registryType', 'registryAssignments.year', 'socialRoles.socialRole', 'memberships'])->findOrFail($id);
        $this->authorize('view', $member);

        return new MemberResource($member);
    }

    #[AssociationPut('/api/v1/associations/members/{id}', 'updateAssociationMember', 'Update a person and the linked contact', 'AssociationMemberRequest')]
    public function update(SaveMemberRequest $request, string $id): MemberResource
    {
        $member = Member::query()->with('contact')->findOrFail($id);
        $this->authorize('update', $member);

        return new MemberResource($this->registry->update($member, $this->withoutHealthUnlessAllowed($request->validated())));
    }

    #[AssociationDelete('/api/v1/associations/members/{id}', 'deleteAssociationMember', 'Remove a person from the registry. The contact stays')]
    public function destroy(string $id): JsonResponse
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('delete', $member);
        $member->delete();

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/members/{id}/registry-types', 'assignAssociationRegistryType', 'Give a registry type to a person for a period', 'AssociationRegistryTypeAssignmentRequest')]
    public function assignType(AssignRegistryTypeRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);
        $type = RegistryType::query()->findOrFail($request->validated('registry_type_id'));

        return new AssociationRecordResource($this->registry->assignType($member, $type, $request->validated()));
    }

    #[AssociationPost('/api/v1/associations/members/{id}/social-roles', 'assignAssociationSocialRole', 'Give an office to a person for a period', 'AssociationSocialRoleAssignmentRequest')]
    public function assignRole(AssignSocialRoleRequest $request, string $id): AssociationRecordResource
    {
        $member = Member::query()->findOrFail($id);
        $this->authorize('update', $member);
        $role = SocialRole::query()->findOrFail($request->validated('social_role_id'));

        return new AssociationRecordResource($this->registry->assignRole($member, $role, $request->validated()));
    }

    /**
     * @return Builder<Member>
     */
    private function filtered(Request $request): Builder
    {
        $today = CarbonImmutable::today()->toDateString();
        $search = trim((string) $request->query('search'));

        return Member::query()
            ->when($request->filled('status'), static fn (Builder $query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('registry_type_id'), static fn (Builder $query) => $query->whereHas('registryAssignments', static fn (Builder $inner) => $inner->where('registry_type_id', (string) $request->query('registry_type_id'))->validOn($today)))
            ->when($request->filled('social_role_id'), static fn (Builder $query) => $query->whereHas('socialRoles', static fn (Builder $inner) => $inner->where('social_role_id', (string) $request->query('social_role_id'))->where(static fn (Builder $open) => $open->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))))
            ->when($request->filled('year_id'), static fn (Builder $query) => $query->whereHas('memberships', static fn (Builder $inner) => $inner->where('year_id', (string) $request->query('year_id'))))
            ->when($request->filled('membership_status'), static fn (Builder $query) => $query->whereHas('memberships', static fn (Builder $inner) => $inner->where('status', (string) $request->query('membership_status'))))
            ->when($search !== '', static fn (Builder $query) => $query->whereHas('contact', static fn (Builder $inner) => $inner->where(static fn (Builder $any) => $any->whereLike('first_name', "%{$search}%")->orWhereLike('last_name', "%{$search}%")->orWhereLike('email', "%{$search}%"))))
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutHealthUnlessAllowed(array $data): array
    {
        return Gate::allows(AssociationCapability::Health->value) ? $data : array_diff_key($data, array_flip(['health_notes', 'allergies']));
    }
}
