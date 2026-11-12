<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\SaveContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreContactRequest;
use App\Http\Requests\Crm\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class CrmController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        private readonly SaveContactAction $saveContact
    ) {}

    #[OA\Get(
        path: '/api/v1/crm/contacts',
        operationId: 'getContacts',
        description: 'List paginated CRM contacts with optional filter by type, country, and search keyword.',
        summary: 'List contacts',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Contact type filter', schema: new OA\Schema(type: 'string', enum: ['client', 'customer', 'supplier', 'both', 'lead'])),
            new OA\Parameter(name: 'country', in: 'query', required: false, description: 'ISO 2-letter country filter', schema: new OA\Schema(type: 'string', example: 'CH')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search name, VAT or fiscal code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contacts retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $query = Contact::with('addresses')->latest();

        if ($request->filled('type')) {
            $type = (string) $request->query('type');
            if ($type === 'customer' || $type === 'client') {
                $query->whereIn('type', ['client', 'customer']);
            } else {
                $query->where('type', $type);
            }
        }

        if ($request->filled('country')) {
            $query->where('country', strtoupper((string) $request->query('country')));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('vat_number', 'like', "%{$search}%")
                    ->orWhere('fiscal_code', 'like', "%{$search}%");
            });
        }

        return $this->successResponse(
            data: $query->paginate(25),
            message: 'Contacts retrieved successfully'
        );
    }

    #[OA\Get(
        path: '/api/v1/crm/clients',
        operationId: 'getClients',
        description: 'List paginated clients/customers.',
        summary: 'List clients',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Clients retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function clients(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $clients = Contact::with('addresses')
            ->whereIn('type', ['client', 'customer', 'both'])
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $clients, message: 'Clients retrieved');
    }

    #[OA\Get(
        path: '/api/v1/crm/suppliers',
        operationId: 'getSuppliers',
        description: 'List paginated suppliers and vendors.',
        summary: 'List suppliers',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Suppliers retrieved successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function suppliers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $suppliers = Contact::with('addresses')
            ->whereIn('type', ['supplier', 'both'])
            ->latest()
            ->paginate(25);

        return $this->successResponse(data: $suppliers, message: 'Suppliers retrieved');
    }

    #[OA\Post(
        path: '/api/v1/crm/contacts',
        operationId: 'createContact',
        description: 'Create a new CRM contact (customer, supplier, or lead) with optional billing address.',
        summary: 'Create contact',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'country'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['client', 'customer', 'supplier', 'both', 'lead'], example: 'client'),
                    new OA\Property(property: 'legal_type', type: 'string', enum: ['company', 'person'], nullable: true, description: 'Company: company_name is required. Person: last_name is required.', example: 'company'),
                    new OA\Property(property: 'country', type: 'string', maxLength: 2, example: 'CH'),
                    new OA\Property(property: 'company_name', type: 'string', maxLength: 255, nullable: true, example: 'Acme SA'),
                    new OA\Property(property: 'first_name', type: 'string', maxLength: 100, nullable: true, example: 'John'),
                    new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true, example: 'Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'john@example.com'),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true, example: '+41 91 123 45 67'),
                    new OA\Property(property: 'vat_number', type: 'string', maxLength: 30, nullable: true, example: 'CHE-123.456.789'),
                    new OA\Property(property: 'fiscal_code', type: 'string', maxLength: 16, nullable: true, example: 'RSSMRA85M01H501Q'),
                    new OA\Property(property: 'uid_number', type: 'string', maxLength: 20, nullable: true, example: 'CHE123456789'),
                    new OA\Property(property: 'sdi_code', type: 'string', maxLength: 7, nullable: true, example: '0000000'),
                    new OA\Property(property: 'pec', type: 'string', format: 'email', nullable: true, example: 'contact@pec.it'),
                    new OA\Property(property: 'document_language', description: 'Language of the PDFs and emails sent to this contact, the language of the user when omitted.', type: 'string', nullable: true, enum: ['it', 'en', 'de', 'fr']),
                    new OA\Property(property: 'iban', type: 'string', maxLength: 34, nullable: true),
                    new OA\Property(property: 'qr_iban', type: 'string', maxLength: 34, nullable: true),
                    new OA\Property(property: 'payment_terms_days', type: 'integer', nullable: true, example: 30),
                    new OA\Property(
                        property: 'address',
                        type: 'object',
                        nullable: true,
                        properties: [
                            new OA\Property(property: 'street', type: 'string', example: 'Via Nassa'),
                            new OA\Property(property: 'house_number', type: 'string', nullable: true, example: '10'),
                            new OA\Property(property: 'postal_code', type: 'string', example: '6900'),
                            new OA\Property(property: 'city', type: 'string', example: 'Lugano'),
                            new OA\Property(property: 'state_canton', type: 'string', nullable: true, example: 'TI'),
                            new OA\Property(property: 'country', type: 'string', maxLength: 2, example: 'CH'),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Contact created successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = $this->saveContact->create($request->validated());

        return $this->successResponse(
            data: new ContactResource($contact),
            message: 'Contact created successfully',
            statusCode: Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/crm/contacts/{id}',
        operationId: 'getContactById',
        description: 'Get contact details by ID including addresses.',
        summary: 'Get contact details',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contact details retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $contact = Contact::with('addresses')->findOrFail($id);
        $this->authorize('view', $contact);

        return $this->successResponse(data: new ContactResource($contact), message: 'Contact details retrieved');
    }

    #[OA\Put(
        path: '/api/v1/crm/contacts/{id}',
        operationId: 'updateContact',
        description: 'Update existing contact details.',
        summary: 'Update contact',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['client', 'customer', 'supplier', 'both', 'lead']),
                    new OA\Property(property: 'legal_type', type: 'string', enum: ['company', 'person'], nullable: true, description: 'Company: company_name is required. Person: last_name is required.'),
                    new OA\Property(property: 'country', type: 'string', maxLength: 2),
                    new OA\Property(property: 'company_name', type: 'string', maxLength: 255, nullable: true),
                    new OA\Property(property: 'first_name', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'last_name', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true),
                    new OA\Property(property: 'vat_number', type: 'string', maxLength: 30, nullable: true),
                    new OA\Property(property: 'fiscal_code', type: 'string', maxLength: 16, nullable: true),
                    new OA\Property(property: 'uid_number', type: 'string', maxLength: 20, nullable: true),
                    new OA\Property(property: 'sdi_code', type: 'string', maxLength: 7, nullable: true),
                    new OA\Property(property: 'pec', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'document_language', description: 'Language of the PDFs and emails sent to this contact, the language of the user when omitted.', type: 'string', nullable: true, enum: ['it', 'en', 'de', 'fr']),
                    new OA\Property(property: 'iban', type: 'string', maxLength: 34, nullable: true),
                    new OA\Property(property: 'qr_iban', type: 'string', maxLength: 34, nullable: true),
                    new OA\Property(property: 'payment_terms_days', type: 'integer', nullable: true),
                    new OA\Property(
                        property: 'address',
                        type: 'object',
                        nullable: true,
                        properties: [
                            new OA\Property(property: 'street', type: 'string'),
                            new OA\Property(property: 'house_number', type: 'string', nullable: true),
                            new OA\Property(property: 'postal_code', type: 'string'),
                            new OA\Property(property: 'city', type: 'string'),
                            new OA\Property(property: 'state_canton', type: 'string', nullable: true),
                            new OA\Property(property: 'country', type: 'string', maxLength: 2),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Contact updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateContactRequest $request, string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('update', $contact);

        $updated = $this->saveContact->update($contact, $request->validated());

        return $this->successResponse(data: new ContactResource($updated), message: 'Contact updated successfully');
    }

    #[OA\Delete(
        path: '/api/v1/crm/contacts/{id}',
        operationId: 'deleteContact',
        description: 'Soft delete a CRM contact.',
        summary: 'Delete contact',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Contact UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contact deleted successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Contact not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('delete', $contact);

        $contact->delete();

        return $this->successResponse(message: 'Contact deleted successfully');
    }
}
