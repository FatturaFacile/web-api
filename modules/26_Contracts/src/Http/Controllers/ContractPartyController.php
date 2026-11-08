<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Modules\Contracts\Enums\ContractKind;
use Modules\Contracts\Http\Requests\SaveContractPartyRequest;
use Modules\Contracts\Models\ContractParty;
use Modules\Contracts\Services\ContractRegister;
use Modules\Procurement\Models\Supplier;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parties linked to a contract besides its counterparty: signatories, guarantors, subcontractors and so on.
 */
final class ContractPartyController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly ContractRegister $register) {}

    /**
     * List the parties of a contract.
     */
    #[OA\Get(
        path: '/api/v1/contract-register/{kind}/{id}/parties',
        operationId: 'listContractParties',
        description: 'The parties linked to the contract, each resolved to a name from the contact, supplier or user it points at.',
        summary: 'List contract parties',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Parties'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 200, description: 'Parties', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractPartyResource'))])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function index(string $kind, string $id): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);
        $parties = ContractParty::query()->where('contract_kind', $kind)->where('contract_id', $contract->getKey())->orderBy('created_at')->get();

        return $this->successResponse($parties->map(fn (ContractParty $party): array => $this->present($party))->all(), 'Contract parties');
    }

    /**
     * Link a party.
     */
    #[OA\Post(
        path: '/api/v1/contract-register/{kind}/{id}/parties',
        operationId: 'createContractParty',
        description: 'Links a contact, supplier or user of the company to the contract with a role, or an external person by name. The same party can hold several roles.',
        summary: 'Link a party to a contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractSavePartyRequest')),
        tags: ['Contract Parties'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath')],
        responses: [
            new OA\Response(response: 201, description: 'Linked', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractPartyResource')])),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ContractConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ContractUnprocessable', response: 422),
        ]
    )]
    public function store(SaveContractPartyRequest $request, string $kind, string $id): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);
        $data = $request->validated();

        $duplicate = ContractParty::query()
            ->where('contract_kind', $kind)->where('contract_id', $contract->getKey())
            ->where('party_type', $data['party_type'])->where('role', $data['role'])
            ->when($data['party_type'] === 'external', fn ($query) => $query->whereRaw('lower(name) = lower(?)', [$data['name']]), fn ($query) => $query->where('party_id', $data['party_id']))
            ->exists();

        if ($duplicate) {
            abort(Response::HTTP_CONFLICT, 'This party already holds this role on the contract.');
        }

        $party = ContractParty::create([...$data, 'contract_kind' => $kind, 'contract_id' => $contract->getKey()]);

        return $this->successResponse($this->present($party), 'Party linked', Response::HTTP_CREATED);
    }

    /**
     * Unlink a party.
     */
    #[OA\Delete(
        path: '/api/v1/contract-register/{kind}/{id}/parties/{partyId}',
        operationId: 'deleteContractParty',
        description: 'Removes the link between a party and the contract. The contact, supplier or user itself is untouched.',
        summary: 'Unlink a party from a contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Contract Parties'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ContractKindPath'), new OA\Parameter(ref: '#/components/parameters/ContractIdPath'), new OA\Parameter(name: 'partyId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Unlinked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/ContractUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ContractForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/ContractNotFound', response: 404),
        ]
    )]
    public function destroy(string $kind, string $id, string $partyId): JsonResponse
    {
        $contract = $this->register->find(ContractKind::from($kind), $id);
        ContractParty::query()->where('contract_kind', $kind)->where('contract_id', $contract->getKey())->findOrFail($partyId)->delete();

        return $this->successResponse(null, 'Party unlinked');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ContractParty $party): array
    {
        return [
            'id' => (string) $party->id,
            'party_type' => $party->party_type,
            'party_id' => $party->party_id,
            'name' => $party->party_type === 'external' ? $party->name : $this->nameOf($party) ?? $party->name,
            'role' => $party->role,
            'email' => $party->email,
            'notes' => $party->notes,
        ];
    }

    private function nameOf(ContractParty $party): ?string
    {
        return match ($party->party_type) {
            'contact' => ($contact = Contact::query()->find($party->party_id)) === null ? null : ((string) ($contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)) ?: null),
            'supplier' => Supplier::query()->find($party->party_id)?->company_name,
            'user' => User::query()->find($party->party_id)?->name,
            default => null,
        };
    }
}
