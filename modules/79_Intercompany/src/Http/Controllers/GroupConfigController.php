<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\GeneralLedger\Enums\ConsolidationAdjustmentType;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\Intercompany\Enums\AnomalyType;
use Modules\Intercompany\Enums\DocumentKind;
use Modules\Intercompany\Enums\LinkStatus;
use Modules\Intercompany\Enums\MatchStatus;
use Modules\Intercompany\Enums\MirrorMode;
use Modules\Intercompany\Enums\NettingStatus;
use Modules\Intercompany\Enums\PartyRole;
use Modules\Intercompany\Enums\PaymentState;
use Modules\Intercompany\Enums\RateType;
use Modules\Intercompany\Enums\RunStatus;
use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Enums\SyncEventType;
use Modules\Intercompany\Exceptions\IntercompanyException;
use Modules\Intercompany\Http\Requests\AsOfDateRequest;
use Modules\Intercompany\Http\Requests\StorePartyLinkRequest;
use Modules\Intercompany\Http\Requests\UpdateSettingsRequest;
use Modules\Intercompany\Http\Resources\PartyLinkResource;
use Modules\Intercompany\Http\Resources\SettingsResource;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Services\ScopeValidationService;
use Modules\Intercompany\Services\SettingsService;
use Modules\Intercompany\Services\StandardProfile;
use Modules\Intercompany\Support\GroupScope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lookups, group settings, party links and the scope validation of a consolidation group.
 */
final class GroupConfigController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GroupScope $scope,
        private readonly SettingsService $settings,
        private readonly ScopeValidationService $validation,
    ) {}

    #[OA\Get(path: '/api/v1/intercompany/lookups', operationId: 'intercompanyLookups', summary: 'Intercompany lookups (enum codes)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], responses: [new OA\Response(response: 200, description: 'Enum codes and standards')])]
    public function lookups(): JsonResponse
    {
        Gate::authorize('intercompany.view');

        $enums = [
            'mirrorMode' => MirrorMode::codes(),
            'linkStatus' => LinkStatus::codes(),
            'documentType' => DocumentKind::codes(),
            'paymentStatus' => PaymentState::codes(),
            'nettingStatus' => NettingStatus::codes(),
            'matchStatus' => MatchStatus::codes(),
            'anomalyType' => AnomalyType::codes(),
            'rateType' => RateType::codes(),
            'runStatus' => RunStatus::codes(),
            'standard' => Standard::codes(),
            'partyRole' => PartyRole::codes(),
            'syncEvent' => SyncEventType::codes(),
            'eliminationType' => [...array_map(static fn ($case): string => $case->value, ConsolidationAdjustmentType::cases()), 'manual'],
            'consolidationMethod' => array_map(static fn ($case): string => $case->value, ConsolidationMethod::cases()),
        ];

        $standards = array_map(static fn (Standard $standard): array => ['code' => $standard->value, 'name_key' => StandardProfile::for($standard)['name_key'], 'framework' => StandardProfile::for($standard)['framework']], Standard::cases());

        return $this->successResponse(['enums' => $enums, 'standards' => $standards], 'Lookups');
    }

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/settings', operationId: 'intercompanyShowSettings', summary: 'Intercompany settings of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function showSettings(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);

        return $this->successResponse(new SettingsResource($this->settings->forGroup($group)), 'Settings');
    }

    #[OA\Put(path: '/api/v1/intercompany/groups/{groupId}/settings', operationId: 'intercompanyUpdateSettings', summary: 'Update the intercompany settings of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Settings updated')])]
    public function updateSettings(UpdateSettingsRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);

        return $this->successResponse(new SettingsResource($this->settings->update($group, $request->validated())), 'Settings updated');
    }

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/party-links', operationId: 'intercompanyListPartyLinks', summary: 'Party links of a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Party links')])]
    public function partyLinks(Request $request, string $groupId): JsonResponse
    {
        Gate::authorize('intercompany.view');
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);

        return $this->successResponse(PartyLinkResource::collection(PartyLink::query()->where('group_id', $group->id)->orderBy('created_at')->get()), 'Party links');
    }

    #[OA\Post(path: '/api/v1/intercompany/groups/{groupId}/party-links', operationId: 'intercompanyStorePartyLink', summary: 'Add a party link', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Party link created')])]
    public function storePartyLink(StorePartyLinkRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $this->scope->assertReadable($request->user(), $group);
        $data = $request->validated();
        $this->scope->assertEntities($group, [$data['entity_tenant_id'], $data['counterparty_tenant_id']]);

        $exists = PartyLink::query()->where('group_id', $group->id)->where('entity_tenant_id', $data['entity_tenant_id'])->where('counterparty_tenant_id', $data['counterparty_tenant_id'])->where('role', $data['role'])->exists();

        if ($exists) {
            throw new IntercompanyException('The party link already exists.', 'party_link_exists');
        }

        $link = $this->scope->runAs((string) $group->tenant_id, fn (): PartyLink => PartyLink::withoutGlobalScopes()->create([...$data, 'tenant_id' => $group->tenant_id, 'group_id' => $group->id]));

        return $this->successResponse(new PartyLinkResource($link), 'Party link created', Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/intercompany/party-links/{id}', operationId: 'intercompanyDeletePartyLink', summary: 'Remove a party link', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Party link removed')])]
    public function destroyPartyLink(Request $request, string $id): JsonResponse
    {
        Gate::authorize('intercompany.manage');
        $link = PartyLink::query()->findOrFail($id);
        $this->scope->assertReadable($request->user(), $this->scope->group((string) $link->group_id));
        $link->delete();

        return $this->successResponse(null, 'Party link removed');
    }

    #[OA\Get(path: '/api/v1/intercompany/groups/{groupId}/scope-validation', operationId: 'intercompanyScopeValidation', summary: 'Scope and tree validation before a consolidation run', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Intercompany'], parameters: [new OA\Parameter(name: 'groupId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'fiscal_period_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Checks')])]
    public function scopeValidation(AsOfDateRequest $request, string $groupId): JsonResponse
    {
        $group = $this->scope->group($groupId);
        $periodId = (string) $request->validated('fiscal_period_id');

        if ($periodId === '') {
            throw new IntercompanyException('The fiscal period is required.', 'period_invalid');
        }

        return $this->successResponse($this->validation->validate($request->user(), $group, $periodId), 'Scope validation');
    }
}
