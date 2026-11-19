<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cpq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cpq\RequestQuoteChangesRequest;
use App\Http\Requests\Cpq\SignQuoteOnlineRequest;
use App\Http\Resources\Cpq\PublicQuoteResource;
use App\Http\Resources\Cpq\QuoteSignatureRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Translation;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Sales\Cpq\QuoteSignatureService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public, token-authenticated pages of the online quote signature: the token is the credential.
 */
class PublicQuoteSignatureController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly QuoteSignatureService $signatures,
        private readonly DocumentPdfService $pdf,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/quote-signatures/{token}',
        operationId: 'showPublicQuoteSignature',
        summary: 'Review a quote to sign',
        description: 'The quote behind a valid signature link, in the language of the link, without cost or margin. Public endpoint: the token is the credential.',
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Quote', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Link unknown, expired, revoked or used'),
        ]
    )]
    public function show(string $token): JsonResponse
    {
        $request = $this->signatures->resolve($token);
        $quote = Quote::query()->withoutGlobalScopes()->with('items')->whereKey($request->quote_id)->firstOrFail();

        return $this->successResponse([
            'locale' => $request->locale,
            'expires_at' => $request->expires_at->toIso8601String(),
            'quote' => new PublicQuoteResource($quote),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/public/quote-signatures/{token}/pdf',
        operationId: 'downloadPublicQuoteSignaturePdf',
        summary: 'Quote PDF in the language of the link',
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 422, description: 'Link unknown, expired, revoked or used'),
        ]
    )]
    public function pdf(string $token): Response
    {
        $request = $this->signatures->resolve($token);
        $quote = Quote::query()->withoutGlobalScopes()->whereKey($request->quote_id)->firstOrFail();
        $chosen = request()->query('locale');
        $locale = is_string($chosen) && in_array($chosen, Translation::SUPPORTED_LOCALES, true) ? $chosen : $request->locale;
        $rendered = $this->pdf->render($quote, ['locale' => $locale]);

        return response($rendered->content, HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$rendered->filename.'"',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/public/quote-signatures/{token}/sign',
        operationId: 'signPublicQuote',
        summary: 'Sign and accept a quote',
        description: 'Signs the quote with a drawn or typed signature, optionally choosing the optional lines and giving a purchase order number. The quote becomes accepted and the audit metadata is stored with the SHA-256 of the accepted payload.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'signer_name', type: 'string'),
            new OA\Property(property: 'signer_role', type: 'string'),
            new OA\Property(property: 'purchase_order_number', type: 'string', nullable: true),
            new OA\Property(property: 'signature_type', type: 'string', enum: ['drawn', 'typed']),
            new OA\Property(property: 'signature_data', type: 'string'),
            new OA\Property(property: 'selected_optional_item_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
            new OA\Property(property: 'accept_terms', type: 'boolean'),
        ])),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Signed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Link not usable or input invalid'),
        ]
    )]
    public function sign(SignQuoteOnlineRequest $request, string $token): JsonResponse
    {
        /** @var array{signer_name: string, signer_role: string, purchase_order_number?: ?string, signature_type: string, signature_data: string, selected_optional_item_ids?: list<string>|null} $data */
        $data = $request->safe()->except('accept_terms');
        $signed = $this->inTenantOf($token, fn () => $this->signatures->sign($token, $data, $request->ip(), $request->userAgent()));

        return $this->successResponse(new QuoteSignatureRequestResource($signed), __('sales.cpq.signed'));
    }

    #[OA\Post(
        path: '/api/v1/public/quote-signatures/{token}/request-changes',
        operationId: 'requestPublicQuoteChanges',
        summary: 'Ask changes to a quote',
        description: 'The customer asks changes instead of signing: the link is closed and the request is recorded on the quote.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Link not usable or input invalid'),
        ]
    )]
    public function requestChanges(RequestQuoteChangesRequest $request, string $token): JsonResponse
    {
        $recorded = $this->inTenantOf($token, fn () => $this->signatures->requestChanges($token, (string) $request->validated('message'), $request->ip(), $request->userAgent()));

        return $this->successResponse(new QuoteSignatureRequestResource($recorded), __('sales.cpq.changes_requested'));
    }

    /**
     * Runs the callback in the company of the quote, so the audit entries it writes carry it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inTenantOf(string $token, callable $callback): mixed
    {
        $tenant = Tenant::query()->findOrFail($this->signatures->resolve($token)->tenant_id);

        return $this->tenantContext->runWithTenant($tenant, $callback);
    }
}
