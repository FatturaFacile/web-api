<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\FiscalLookupRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Services\Crm\Fiscal\FiscalLookupService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ContactFiscalController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(private readonly FiscalLookupService $lookup) {}

    #[OA\Post(
        path: '/api/v1/crm/fiscal/lookup',
        operationId: 'lookupFiscalData',
        description: 'Validate an Italian Partita IVA (VIES) or a Swiss UID (Zefix) and return the registry data to autofill a customer. A registry outage degrades to the formal checksum check.',
        summary: 'Fiscal registry lookup',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['country'], properties: [
            new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH']),
            new OA\Property(property: 'vat_number', type: 'string', example: '01234567897'),
            new OA\Property(property: 'uid_number', type: 'string', example: 'CHE-123.456.788'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Lookup result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Formally invalid identifier', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function lookup(FiscalLookupRequest $request): JsonResponse
    {
        $country = (string) $request->validated('country');
        $identifier = (string) ($country === 'CH'
            ? ($request->validated('uid_number') ?? $request->validated('vat_number'))
            : ($request->validated('vat_number') ?? $request->validated('uid_number')));

        $result = $this->lookup->lookup($country, $identifier);

        if (! $result['valid_format']) {
            return $this->unprocessable($result['warnings'][0] ?? 'Invalid fiscal identifier');
        }

        return $this->successResponse($result, 'Fiscal lookup completed');
    }

    #[OA\Post(
        path: '/api/v1/crm/contacts/{id}/fiscal/verify',
        operationId: 'verifyContactFiscalData',
        description: 'Re-run the registry lookup on the stored VAT number / UID of the contact and stamp the certification source and date.',
        summary: 'Certify contact fiscal data',
        security: [['bearerAuth' => []]],
        tags: ['CRM', 'Contacts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Verification result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Nothing to verify or formally invalid', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(string $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('update', $contact);

        if (! in_array($contact->country, ['IT', 'CH'], true)) {
            return $this->unprocessable('Registry verification is only available for Italian and Swiss customers.');
        }

        $identifier = $contact->country === 'CH' ? ($contact->uid_number ?: $contact->vat_number) : $contact->vat_number;

        if (empty($identifier)) {
            return $this->unprocessable('The contact has no VAT number / UID to verify.');
        }

        $result = $this->lookup->lookup($contact->country, $identifier);

        if (! $result['valid_format']) {
            return $this->unprocessable($result['warnings'][0] ?? 'Invalid fiscal identifier');
        }

        $contact->update(['fiscal_verified_at' => now(), 'fiscal_verification_source' => $result['source']]);

        return $this->successResponse($result, 'Fiscal data verified');
    }

    private function unprocessable(string $detail): JsonResponse
    {
        return $this->problemDetailsResponse('Invalid fiscal data', $detail, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
