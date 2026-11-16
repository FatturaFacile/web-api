<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\RequestAffiliationRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\NetworkLink;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\NetworkService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Affiliation to a parent body, governed by the explicit consent of the affiliate.
 */
final class NetworkController extends AssociationController
{
    public function __construct(private readonly NetworkService $network) {}

    #[AssociationGet('/api/v1/associations/network/code', 'showAssociationAffiliationCode', 'Show the code that a parent body needs to ask for affiliation')]
    public function code(): JsonResponse
    {
        $this->allow(AssociationCapability::Network);

        return response()->json(['data' => ['affiliation_code' => $this->network->affiliationCode()]]);
    }

    #[AssociationPost('/api/v1/associations/network/affiliates', 'requestAssociationAffiliation', 'As a parent body, ask a company to affiliate. Nothing is visible before its consent', 'AssociationAffiliationRequest')]
    public function requestAffiliation(RequestAffiliationRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Network);
        $link = $this->network->requestAffiliation((string) $request->validated('affiliation_code'));

        return response()->json(['data' => ['status' => $link->status, 'requested_at' => $link->requested_at]], 201);
    }

    #[AssociationGet('/api/v1/associations/network/consents', 'listAssociationNetworkConsents', 'As an affiliate, list the parent bodies and the consents given')]
    public function consents(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Network);

        return AssociationRecordResource::collection(NetworkLink::query()->with('parent:id,name')->orderByDesc('requested_at')->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/network/consents/{id}/grant', 'grantAssociationNetworkConsent', 'As an affiliate, consent to share aggregated figures with a parent body')]
    public function grant(Request $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Network);

        return new AssociationRecordResource($this->network->grantConsent(NetworkLink::query()->findOrFail($id), $this->actorId($request)));
    }

    #[AssociationPost('/api/v1/associations/network/consents/{id}/withdraw', 'withdrawAssociationNetworkConsent', 'As an affiliate, withdraw the consent. The parent body stops seeing the figures at once')]
    public function withdraw(Request $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Network);

        return new AssociationRecordResource($this->network->withdrawConsent(NetworkLink::query()->findOrFail($id), $this->actorId($request)));
    }

    #[AssociationGet('/api/v1/associations/network/figures', 'showAssociationNetworkFigures', 'As a parent body, read the aggregated figures of the affiliates that gave consent')]
    public function figures(): JsonResponse
    {
        $this->allow(AssociationCapability::Network);

        return response()->json(['data' => $this->network->figures()]);
    }

    #[AssociationGet('/api/v1/associations/network/statements/{year}', 'showAssociationNetworkStatements', 'As a parent body, read the aggregated statements and the compliance flags of the affiliates that gave consent; never personal data', response: 'AssociationNetworkStatements')]
    public function statements(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Network);

        return response()->json(['data' => $this->network->statements((int) $year)]);
    }
}
