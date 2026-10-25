<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\SendPaymentReminder;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PaymentReminderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SendPaymentReminder $sendReminder) {}

    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}/reminders',
        operationId: 'getInvoiceReminders',
        description: 'List issued payment reminders and dunning letters for an invoice.',
        summary: 'List payment reminders',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales', 'Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reminders list retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        return $this->successResponse(
            CatalogResource::collection($invoice->reminders()->orderBy('level')->orderBy('created_at')->get()),
            __('sales.messages.reminders_list')
        );
    }

    /**
     * Manually send the next-level reminder, regardless of grace days and interval.
     */
    #[OA\Post(
        path: '/api/v1/sales/invoices/{id}/reminders',
        operationId: 'sendPaymentReminder',
        description: 'Manually send the next-level payment reminder to the customer email.',
        summary: 'Send payment reminder',
        security: [['bearerAuth' => []]],
        tags: ['Invoices', 'Sales', 'Reminders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Invoice UUID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Reminder queued successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Invoice not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function store(string $id): JsonResponse
    {
        $reminder = $this->sendReminder->execute(Invoice::with('contact')->findOrFail($id), force: true);

        return $this->successResponse(new CatalogResource($reminder), __('sales.messages.reminder_queued'), Response::HTTP_CREATED);
    }
}
