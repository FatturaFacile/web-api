<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Http\Requests\CheckIbanRequest;
use Modules\Treasury\Http\Requests\InitiateOpenBankingPaymentRequest;
use Modules\Treasury\Http\Requests\StoreOpenBankingConsentRequest;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;
use Modules\Treasury\Services\IbanValidator;
use Modules\Treasury\Services\OpenBankingService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

final class OpenBankingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OpenBankingService $openBanking,
        private readonly IbanValidator $ibans,
    ) {}

    #[OA\Post(
        path: '/api/v1/treasury/iban/check',
        operationId: 'checkIban',
        description: 'Check an IBAN: format, country, country length and mod-97 check digits. It does not prove that the account exists.',
        summary: 'Check IBAN',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCheckIbanRequest')),
        tags: ['Treasury Open Banking'],
        responses: [
            new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryIbanCheck')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function checkIban(CheckIbanRequest $request): JsonResponse
    {
        return $this->successResponse($this->ibans->check($request->validated('iban')));
    }

    #[OA\Post(
        path: '/api/v1/treasury/open-banking/consents',
        operationId: 'createOpenBankingConsent',
        description: 'Ask the provider for access to an account. Without a provider the answer is 503.',
        summary: 'Create consent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryStoreOpenBankingConsentRequest')),
        tags: ['Treasury Open Banking'],
        responses: [
            new OA\Response(response: 201, description: 'Consent', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryOpenBankingConsent')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 503, description: 'No provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storeConsent(StoreOpenBankingConsentRequest $request): JsonResponse
    {
        return $this->withProvider(fn (): JsonResponse => $this->successResponse(
            $this->presentConsent($this->openBanking->createConsent($request->validated('iban'), (string) $request->user()->id)),
            'Consent stored',
            Response::HTTP_CREATED
        ));
    }

    #[OA\Get(
        path: '/api/v1/treasury/open-banking/consents/{id}/account',
        operationId: 'getOpenBankingAccount',
        description: 'Balance and transactions of the consented account.',
        summary: 'Read account information',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Open Banking'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account information', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryOpenBankingAccountInformation')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Consent no longer active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 503, description: 'No provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function account(Request $request, string $id): JsonResponse
    {
        $consent = OpenBankingConsent::query()->findOrFail($id);
        $from = (string) $request->query('from', now()->subDays(30)->toDateString());
        $to = (string) $request->query('to', now()->toDateString());

        return $this->withProvider(fn (): JsonResponse => $this->successResponse($this->openBanking->accountInformation($consent, $from, $to)));
    }

    #[OA\Post(
        path: '/api/v1/treasury/open-banking/payments',
        operationId: 'initiateOpenBankingPayment',
        description: 'Initiate a payment from a consented account. A supplier invoice waiting for approval or rejected is refused.',
        summary: 'Initiate payment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TreasuryInitiateOpenBankingPaymentRequest')),
        tags: ['Treasury Open Banking'],
        responses: [
            new OA\Response(response: 201, description: 'Payment', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryOpenBankingPayment')])),
            new OA\Response(response: 409, description: 'Consent no longer active', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 503, description: 'No provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function initiatePayment(InitiateOpenBankingPaymentRequest $request): JsonResponse
    {
        $consent = OpenBankingConsent::query()->findOrFail($request->validated('consent_id'));

        return $this->withProvider(function () use ($request, $consent): JsonResponse {
            $payment = $this->openBanking->initiatePayment($consent, $request->safe()->except('consent_id'), (string) $request->user()->id);

            return $this->successResponse($this->presentPayment($payment), 'Payment initiated', Response::HTTP_CREATED);
        });
    }

    #[OA\Get(
        path: '/api/v1/treasury/open-banking/payments/{id}',
        operationId: 'getOpenBankingPayment',
        description: 'A payment and its current status at the provider.',
        summary: 'Show payment',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Open Banking'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Payment', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryOpenBankingPayment')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 503, description: 'No provider', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showPayment(string $id): JsonResponse
    {
        $payment = OpenBankingPayment::query()->findOrFail($id);

        return $this->withProvider(function () use ($payment): JsonResponse {
            $consent = OpenBankingConsent::query()->findOrFail($payment->consent_id);
            $payment->update(['status' => $this->openBanking->gateway()->paymentStatus((string) $consent->access_token, $payment->external_payment_id)]);

            return $this->successResponse($this->presentPayment($payment));
        });
    }

    /**
     * @param  Closure(): JsonResponse  $call
     */
    private function withProvider(Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (OpenBankingUnavailableException $exception) {
            return $this->problemDetailsResponse('Open banking not available', $exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConsent(OpenBankingConsent $consent): array
    {
        return [
            'id' => (string) $consent->id,
            'driver' => $consent->driver,
            'iban' => $consent->iban,
            'status' => $consent->status,
            'expires_at' => $consent->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayment(OpenBankingPayment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'creditor_iban' => $payment->creditor_iban,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'external_payment_id' => $payment->external_payment_id,
            'status' => $payment->status,
        ];
    }
}
