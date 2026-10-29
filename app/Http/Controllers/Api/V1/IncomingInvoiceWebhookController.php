<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ReceivePassiveInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdi\ReceiveIncomingInvoiceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\EiInboundLog;
use App\Models\Tenant;
use App\Services\ItalianSdi\FatturaPaParser;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class IncomingInvoiceWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FatturaPaParser $parser,
        private readonly ReceivePassiveInvoice $receive,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Receive a supplier invoice pushed by the SDI gateway. The tenant is resolved from the recipient VAT number
     * inside the XML; delivery is idempotent through a fingerprint of the payload.
     */
    #[OA\Post(
        path: '/api/v1/webhooks/sdi/incoming-invoice',
        operationId: 'incomingInvoiceWebhook',
        description: 'Receive a supplier invoice pushed by the SDI gateway. The tenant is resolved from the recipient VAT number inside the XML; delivery is idempotent through a fingerprint of the payload.',
        summary: 'Receive incoming supplier invoice via SDI',
        tags: ['Webhooks', 'SDI'],
        parameters: [
            new OA\Parameter(
                name: 'X-Webhook-Secret',
                in: 'header',
                required: true,
                description: 'Shared webhook secret for provider authentication',
                schema: new OA\Schema(type: 'string')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['xml'],
                properties: [
                    new OA\Property(property: 'xml', type: 'string', description: 'Raw FatturaPA XML content', example: '<?xml version="1.0" encoding="UTF-8"?><p:FatturaElettronica ...>...</p:FatturaElettronica>'),
                    new OA\Property(property: 'file_name', type: 'string', maxLength: 255, nullable: true, example: 'IT01234567890_11111.xml'),
                    new OA\Property(property: 'provider', type: 'string', maxLength: 30, nullable: true, example: 'aruba'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Supplier invoice processed and created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 200,
                description: 'Duplicate webhook detected, previously processed',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid webhook secret',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid FatturaPA XML or unknown recipient tenant',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')
            ),
        ]
    )]
    public function __invoke(ReceiveIncomingInvoiceRequest $request): JsonResponse
    {
        $xml = (string) $request->validated('xml');
        $provider = (string) ($request->validated('provider') ?? 'local');
        $fingerprint = hash('sha256', $provider.'|incoming|'.$xml);

        // Fast-path: duplicate check before locking or parsing
        $existing = EiInboundLog::where('fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            return $this->successResponse(['status' => $existing->status, 'duplicate' => true], __('purchases.messages.webhook_received'));
        }

        try {
            $vat = $this->parser->recipientVat($xml);
        } catch (InvalidArgumentException $e) {
            return $this->problemDetailsResponse(__('purchases.invalid_fatturapa_title'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $tenant = $vat !== null ? Tenant::whereIn('vat_number', [$vat, 'IT'.$vat])->first() : null;

        if ($tenant === null) {
            return $this->problemDetailsResponse(__('purchases.unknown_recipient_title'), __('purchases.unknown_recipient'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lock = Cache::lock("webhook:sdi:incoming:{$fingerprint}", 20);

        try {
            return $lock->block(10, function () use ($request, $xml, $provider, $fingerprint, $tenant): JsonResponse {
                // Double-check idempotency under lock
                $existing = EiInboundLog::where('fingerprint', $fingerprint)->first();
                if ($existing !== null) {
                    return $this->successResponse(
                        ['status' => $existing->status, 'duplicate' => true],
                        __('purchases.messages.webhook_received')
                    );
                }

                try {
                    return DB::transaction(function () use ($request, $xml, $provider, $fingerprint, $tenant): JsonResponse {
                        $results = $this->tenantContext->runWithTenant(
                            $tenant,
                            fn (): array => $this->receive->execute($xml, $request->validated('file_name'))
                        );

                        EiInboundLog::create([
                            'tenant_id' => $tenant->id,
                            'provider' => $provider,
                            'event_type' => 'INVOICE_RECEIVED',
                            'fingerprint' => $fingerprint,
                            'payload' => [
                                'file_name' => $request->validated('file_name'),
                                'passive_invoice_ids' => array_map(fn (array $result): string => $result['invoice']->id, $results),
                            ],
                            'status' => 'processed',
                            'processed_at' => now(),
                        ]);

                        return $this->successResponse(
                            ['status' => 'processed', 'duplicate' => false, 'documents' => count($results)],
                            __('purchases.messages.webhook_received'),
                            Response::HTTP_CREATED
                        );
                    }, 5);
                } catch (UniqueConstraintViolationException) {
                    // Safe recovery if concurrent request committed ahead in another worker
                    $existing = EiInboundLog::where('fingerprint', $fingerprint)->first();

                    return $this->successResponse(
                        ['status' => $existing?->status ?? 'processed', 'duplicate' => true],
                        __('purchases.messages.webhook_received')
                    );
                }
            });
        } catch (LockTimeoutException) {
            $existing = EiInboundLog::where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                return $this->successResponse(
                    ['status' => $existing->status, 'duplicate' => true],
                    __('purchases.messages.webhook_received')
                );
            }

            return $this->problemDetailsResponse(
                __('purchases.invalid_fatturapa_title'),
                'Webhook processing is currently in progress by another worker.',
                Response::HTTP_CONFLICT
            );
        }
    }
}
