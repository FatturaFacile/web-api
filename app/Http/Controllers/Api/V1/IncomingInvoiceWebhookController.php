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
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
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
    public function __invoke(ReceiveIncomingInvoiceRequest $request): JsonResponse
    {
        $xml = (string) $request->validated('xml');
        $provider = (string) ($request->validated('provider') ?? 'local');
        $fingerprint = hash('sha256', $provider.'|incoming|'.$xml);

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

        $results = $this->tenantContext->runWithTenant($tenant, fn (): array => $this->receive->execute($xml, $request->validated('file_name')));

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
    }
}
