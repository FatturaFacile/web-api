<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\SwissEbill\Application\Commands\CancelEbillTransmissionHandler;
use Modules\SwissEbill\Application\Commands\RetryEbillTransmissionHandler;
use Modules\SwissEbill\Application\Commands\TransmitInvoiceToEbillCommand;
use Modules\SwissEbill\Application\Commands\TransmitInvoiceToEbillHandler;
use Modules\SwissEbill\Application\Queries\GetEbillDashboardHandler;
use Modules\SwissEbill\Application\Queries\GetInvoiceEbillHandler;
use Modules\SwissEbill\Application\Queries\ListEbillTransmissionsHandler;
use Modules\SwissEbill\Application\Queries\ListEbillTransmissionsQuery;
use Modules\SwissEbill\Domain\Enums\EbillEventSource;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Http\Controllers\Concerns\InteractsWithEbill;
use Modules\SwissEbill\Http\Requests\ListTransmissionsRequest;
use Modules\SwissEbill\Http\Requests\NoInputRequest;
use Modules\SwissEbill\Http\Resources\EbillTransmissionResource;
use Modules\SwissEbill\Models\EbillTransmission;
use OpenApi\Attributes as OA;

/**
 * Sending invoices through the Swiss eBill network and following them: the monitoring dashboard, the transmission log, the lifecycle
 * of one invoice, retry, recall and "revise and resend", and the audit download of the SIX XML payload.
 */
final class EbillTransmissionController extends Controller
{
    use ApiResponse;
    use InteractsWithEbill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/swiss-ebill/dashboard', operationId: 'swissEbillDashboard', summary: 'KPIs of the eBill monitoring dashboard', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Counts and amounts per stage')])]
    public function dashboard(ListTransmissionsRequest $request, GetEbillDashboardHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', EbillTransmission::class);

        return $this->successResponse($handler->handle($request->input('from'), $request->input('to')), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/swiss-ebill/transmissions', operationId: 'swissEbillListTransmissions', summary: 'The eBill transmission log', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Transmissions, paginated')])]
    public function index(ListTransmissionsRequest $request, ListEbillTransmissionsHandler $handler): JsonResponse
    {
        Gate::authorize('viewAny', EbillTransmission::class);

        return $this->paged($handler->handle(new ListEbillTransmissionsQuery($request->safe()->only(['ebill_status', 'from', 'to', 'customer_id', 'invoice_id', 'channel_type', 'search']), $this->perPage())), EbillTransmissionResource::class);
    }

    #[OA\Get(path: '/api/v1/swiss-ebill/transmissions/{id}', operationId: 'swissEbillShowTransmission', summary: 'One transmission with its event timeline', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transmission')])]
    public function show(string $id): JsonResponse
    {
        $transmission = EbillTransmission::query()->with(['events', 'invoice:id,invoice_number', 'contact:id,company_name,first_name,last_name'])->findOrFail($id);
        Gate::authorize('view', $transmission);

        return $this->successResponse(new EbillTransmissionResource($transmission), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/swiss-ebill/transmissions/{id}/payload', operationId: 'swissEbillDownloadPayload', summary: 'Download the SIX eBill XML payload that was transmitted (audit)', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'XML file')])]
    public function payload(string $id): Response
    {
        $transmission = EbillTransmission::query()->findOrFail($id);
        Gate::authorize('view', $transmission);

        if ($transmission->xml_payload === null) {
            throw new EbillException('payload_unavailable', 'No payload is stored for this transmission.', 404);
        }

        $transmission->appendEvent('payload_downloaded', EbillEventSource::User, null, null, ['xml_sha256' => $transmission->xml_sha256], null, null, $this->userId());

        return response($transmission->xml_payload, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ebill-'.$transmission->id.'.xml"',
            'X-Payload-Sha256' => (string) $transmission->xml_sha256,
        ]);
    }

    #[OA\Get(path: '/api/v1/swiss-ebill/invoices/{id}', operationId: 'swissEbillInvoiceLifecycle', summary: 'The eBill lifecycle of an invoice: transmissions, timeline and pre-flight check', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Transmissions and violations')])]
    public function invoice(string $id, GetInvoiceEbillHandler $handler): JsonResponse
    {
        Invoice::query()->findOrFail($id);
        Gate::authorize('viewAny', EbillTransmission::class);

        $result = $handler->handle($id);

        return $this->successResponse([
            'transmissions' => EbillTransmissionResource::collection($result['transmissions']),
            'violations' => $result['violations'],
            'can_send' => $result['violations'] === [] && ! $result['transmissions']->contains(fn (EbillTransmission $t): bool => ! in_array($t->status->value, ['rejected_by_debtor', 'cancelled', 'failed_technical'], true)),
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/invoices/{id}/send', operationId: 'swissEbillSendInvoice', summary: 'Send an issued invoice through the Swiss eBill network', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 202, description: 'The transmission was queued'), new OA\Response(response: 422, description: 'The payload lacks mandatory data (violations listed)')])]
    public function send(string $id, NoInputRequest $request, TransmitInvoiceToEbillHandler $handler): JsonResponse
    {
        Gate::authorize('create', EbillTransmission::class);

        $transmission = $handler->handle(new TransmitInvoiceToEbillCommand($id, $this->userId()));

        return $this->successResponse(new EbillTransmissionResource($transmission), $this->message('transmission_queued'), 202);
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/transmissions/{id}/revise', operationId: 'swissEbillReviseTransmission', summary: 'Rebuild the payload and send the invoice again after a rejection', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 202, description: 'A new transmission was queued')])]
    public function revise(string $id, NoInputRequest $request, TransmitInvoiceToEbillHandler $handler): JsonResponse
    {
        $previous = EbillTransmission::query()->findOrFail($id);
        Gate::authorize('update', $previous);

        $transmission = $handler->handle(new TransmitInvoiceToEbillCommand($previous->invoice_id, $this->userId(), $previous->id));

        return $this->successResponse(new EbillTransmissionResource($transmission), $this->message('transmission_queued'), 202);
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/transmissions/{id}/retry', operationId: 'swissEbillRetryTransmission', summary: 'Send a technically failed transmission again', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 202, description: 'The transmission was queued again')])]
    public function retry(string $id, NoInputRequest $request, RetryEbillTransmissionHandler $handler): JsonResponse
    {
        Gate::authorize('update', EbillTransmission::query()->findOrFail($id));

        return $this->successResponse(new EbillTransmissionResource($handler->handle($id, $this->userId())), $this->message('transmission_queued'), 202);
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/transmissions/{id}/cancel', operationId: 'swissEbillCancelTransmission', summary: 'Recall an invoice the debtor has not approved yet', security: self::SECURITY, tags: ['Swiss eBill'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The transmission was cancelled')])]
    public function cancel(string $id, NoInputRequest $request, CancelEbillTransmissionHandler $handler): JsonResponse
    {
        Gate::authorize('update', EbillTransmission::query()->findOrFail($id));

        return $this->successResponse(new EbillTransmissionResource($handler->handle($id, $this->userId())), $this->message('transmission_cancelled'));
    }
}
