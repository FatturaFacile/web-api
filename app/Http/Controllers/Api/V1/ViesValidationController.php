<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Fiscal\VatValidationServiceInterface;
use App\DTOs\Fiscal\VatValidationResult;
use App\Exceptions\Fiscal\InvalidVatFormatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\ViesValidateRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\VatValidationLog;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ViesValidationController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(private readonly VatValidationServiceInterface $vies) {}

    #[OA\Post(
        path: '/api/v1/fiscal/vies/validate',
        operationId: 'validateViesVat',
        description: 'Checks an EU VAT number: formal syntax first (no network call when it fails), then the active intra-Community status in VIES. A VIES outage answers 200 with status "unavailable" and never blocks the caller. Every consultation is logged; with contact_id the result is stamped on the CRM counterparty.',
        summary: 'Validate an EU VAT number in VIES',
        security: [['bearerAuth' => []]],
        tags: ['Fiscal', 'CRM'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['vat_number'], properties: [
            new OA\Property(property: 'vat_number', type: 'string', example: 'DE123456789'),
            new OA\Property(property: 'country_code', type: 'string', example: 'DE'),
            new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'valid, invalid or unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 422, description: 'Formally invalid VAT number', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function validate(ViesValidateRequest $request): JsonResponse
    {
        $contact = null;
        if ($request->filled('contact_id')) {
            $contact = Contact::findOrFail((string) $request->validated('contact_id'));
            $this->authorize('update', $contact);
        }

        try {
            $result = $this->vies->validate(
                (string) $request->validated('vat_number'),
                $request->validated('country_code') !== null ? (string) $request->validated('country_code') : $contact?->country,
            );
        } catch (InvalidVatFormatException $e) {
            return $this->problemDetailsResponse('Invalid VAT number', $e->getMessage(), 422);
        }

        VatValidationLog::create([
            'contact_id' => $contact?->id,
            'user_id' => $request->user()?->id,
            'country_code' => $result->countryCode,
            'vat_number' => $result->vatNumber,
            'status' => $result->status,
            'is_valid' => $result->isValid,
            'trader_name' => $result->traderName,
            'trader_address' => $result->traderAddress,
            'request_identifier' => $result->requestIdentifier,
            'error_code' => $result->errorCode,
            'validated_at' => $result->validatedAt,
        ]);

        // Only a definitive verdict certifies the counterparty; an outage leaves it untouched.
        if ($contact !== null && $result->status === VatValidationResult::STATUS_VALID) {
            $contact->forceFill([
                'fiscal_verified_at' => $result->validatedAt,
                'fiscal_verification_source' => 'vies',
            ])->save();
        }

        return $this->successResponse($result->toArray(), 'VIES validation completed');
    }

    #[OA\Get(
        path: '/api/v1/fiscal/vies/logs',
        operationId: 'listViesValidationLogs',
        description: 'VIES consultations of the active company, newest first; optionally filtered by contact_id.',
        summary: 'VIES audit log',
        security: [['bearerAuth' => []]],
        tags: ['Fiscal', 'CRM'],
        responses: [new OA\Response(response: 200, description: 'Consultations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))]
    )]
    public function logs(Request $request): JsonResponse
    {
        $logs = VatValidationLog::query()
            ->when($request->query('contact_id'), fn ($q, $id) => $q->where('contact_id', $id))
            ->orderByDesc('validated_at')
            ->limit(100)
            ->get();

        return $this->successResponse($logs, 'VIES log');
    }
}
