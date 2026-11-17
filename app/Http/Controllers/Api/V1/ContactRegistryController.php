<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ContactAddressRequest;
use App\Http\Requests\Crm\ContactPersonRequest;
use App\Http\Requests\Crm\UpdateContactConsentsRequest;
use App\Http\Resources\ContactAddressResource;
use App\Http\Resources\ContactConsentResource;
use App\Http\Resources\ContactPersonResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\ContactPerson;
use App\Services\Crm\ContactAddressService;
use App\Services\Crm\ContactConsentService;
use App\Services\Crm\ContactHierarchyService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sub-resources of the customer registry: contact persons by role, unlimited typed addresses with per-type defaults,
 * GDPR / nLPD consents and the corporate hierarchy.
 */
class ContactRegistryController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        private readonly ContactAddressService $addresses,
        private readonly ContactConsentService $consents,
        private readonly ContactHierarchyService $hierarchy,
    ) {}

    #[OA\Get(path: '/api/v1/crm/contacts/{id}/persons', operationId: 'listContactPersons', summary: 'List contact persons', description: 'Role-based address book of a customer (administration, purchasing, logistics...).', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Persons retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function persons(string $id): JsonResponse
    {
        $contact = $this->contact($id, 'view');
        $query = $contact->persons()->orderByDesc('is_primary')->orderBy('last_name');

        if (request()->filled('role')) {
            $query->where('role', (string) request()->query('role'));
        }

        return $this->successResponse(ContactPersonResource::collection($query->get()), 'Contact persons retrieved');
    }

    #[OA\Post(path: '/api/v1/crm/contacts/{id}/persons', operationId: 'createContactPerson', summary: 'Add contact person', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Person created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function storePerson(ContactPersonRequest $request, string $id): JsonResponse
    {
        $contact = $this->contact($id, 'update');
        $data = $request->validated();
        $primary = (bool) ($data['is_primary'] ?? false) || ! $contact->persons()->exists();

        if ($primary) {
            $contact->persons()->update(['is_primary' => false]);
        }

        $person = $contact->persons()->create([...$data, 'tenant_id' => $contact->tenant_id, 'is_primary' => $primary]);

        return $this->successResponse(new ContactPersonResource($person), 'Contact person created', Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/crm/contacts/{id}/persons/{personId}', operationId: 'updateContactPerson', summary: 'Update contact person', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'personId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Person updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updatePerson(ContactPersonRequest $request, string $id, string $personId): JsonResponse
    {
        $contact = $this->contact($id, 'update');
        $person = $contact->persons()->findOrFail($personId);
        $data = $request->validated();

        if (($data['is_primary'] ?? false) === true) {
            $contact->persons()->whereKeyNot($person->id)->update(['is_primary' => false]);
        }

        $person->update($data);

        return $this->successResponse(new ContactPersonResource($person->refresh()), 'Contact person updated');
    }

    #[OA\Delete(path: '/api/v1/crm/contacts/{id}/persons/{personId}', operationId: 'deleteContactPerson', summary: 'Delete contact person', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'personId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Person deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function destroyPerson(string $id, string $personId): JsonResponse
    {
        $contact = $this->contact($id, 'update');
        /** @var ContactPerson $person */
        $person = $contact->persons()->findOrFail($personId);
        $wasPrimary = $person->is_primary;
        $person->delete();

        if ($wasPrimary) {
            $contact->persons()->orderBy('created_at')->first()?->update(['is_primary' => true]);
        }

        return $this->successResponse(null, 'Contact person deleted');
    }

    #[OA\Get(path: '/api/v1/crm/contacts/{id}/addresses', operationId: 'listContactAddresses', summary: 'List contact addresses', description: 'Unlimited addresses per customer: legal, billing, shipping and site, with one default per type.', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['legal', 'billing', 'shipping', 'site']))], responses: [new OA\Response(response: 200, description: 'Addresses retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function addresses(string $id): JsonResponse
    {
        $contact = $this->contact($id, 'view');
        $query = $contact->addresses()->orderBy('type')->orderByDesc('is_default')->orderBy('created_at');

        if (request()->filled('type')) {
            $query->where('type', (string) request()->query('type'));
        }

        return $this->successResponse(ContactAddressResource::collection($query->get()), 'Contact addresses retrieved');
    }

    #[OA\Post(path: '/api/v1/crm/contacts/{id}/addresses', operationId: 'createContactAddress', summary: 'Add contact address', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Address created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function storeAddress(ContactAddressRequest $request, string $id): JsonResponse
    {
        $address = $this->addresses->create($this->contact($id, 'update'), $request->validated());

        return $this->successResponse(new ContactAddressResource($address), 'Contact address created', Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/crm/contacts/{id}/addresses/{addressId}', operationId: 'updateContactAddress', summary: 'Update contact address', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'addressId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Address updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updateAddress(ContactAddressRequest $request, string $id, string $addressId): JsonResponse
    {
        $address = $this->address($this->contact($id, 'update'), $addressId);

        return $this->successResponse(new ContactAddressResource($this->addresses->update($address, $request->validated())), 'Contact address updated');
    }

    #[OA\Post(path: '/api/v1/crm/contacts/{id}/addresses/{addressId}/default', operationId: 'setDefaultContactAddress', summary: 'Make address the default of its type', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'addressId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Default address set', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function setDefaultAddress(string $id, string $addressId): JsonResponse
    {
        $address = $this->address($this->contact($id, 'update'), $addressId);

        return $this->successResponse(new ContactAddressResource($this->addresses->setDefault($address)), 'Default address updated');
    }

    #[OA\Delete(path: '/api/v1/crm/contacts/{id}/addresses/{addressId}', operationId: 'deleteContactAddress', summary: 'Delete contact address', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'addressId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Address deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function destroyAddress(string $id, string $addressId): JsonResponse
    {
        $this->addresses->delete($this->address($this->contact($id, 'update'), $addressId));

        return $this->successResponse(null, 'Contact address deleted');
    }

    #[OA\Get(path: '/api/v1/crm/contacts/{id}/consents', operationId: 'getContactConsents', summary: 'Get privacy consents', description: 'Granular marketing, profiling and operational consents (GDPR for IT, nLPD for CH).', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Consents retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function showConsents(string $id): JsonResponse
    {
        return $this->successResponse(ContactConsentResource::collection($this->consents->current($this->contact($id, 'view'))), 'Consents retrieved');
    }

    #[OA\Put(path: '/api/v1/crm/contacts/{id}/consents', operationId: 'updateContactConsents', summary: 'Record privacy consents', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Consents recorded', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updateConsents(UpdateContactConsentsRequest $request, string $id): JsonResponse
    {
        $contact = $this->contact($id, 'update');
        $this->consents->record($contact, $request->validated('consents'), (string) $request->user()?->id);

        return $this->successResponse(ContactConsentResource::collection($this->consents->current($contact)), 'Consents recorded');
    }

    #[OA\Get(path: '/api/v1/crm/contacts/{id}/hierarchy', operationId: 'getContactHierarchy', summary: 'Get corporate hierarchy', description: 'Ancestors (holding chain) and descendant tree (subsidiaries, branches) of a customer.', security: [['bearerAuth' => []]], tags: ['CRM', 'Contacts'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Hierarchy retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function hierarchy(string $id): JsonResponse
    {
        $contact = $this->contact($id, 'view');

        return $this->successResponse([
            'ancestors' => $this->hierarchy->ancestors($contact),
            'tree' => $this->hierarchy->tree($contact),
        ], 'Contact hierarchy retrieved');
    }

    private function contact(string $id, string $ability): Contact
    {
        $contact = Contact::findOrFail($id);
        $this->authorize($ability, $contact);

        return $contact;
    }

    private function address(Contact $contact, string $addressId): ContactAddress
    {
        /** @var ContactAddress */
        return $contact->addresses()->findOrFail($addressId);
    }
}
