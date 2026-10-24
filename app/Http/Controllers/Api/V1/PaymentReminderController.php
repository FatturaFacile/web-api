<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\SendPaymentReminder;
use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentReminderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SendPaymentReminder $sendReminder) {}

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
    public function store(string $id): JsonResponse
    {
        $reminder = $this->sendReminder->execute(Invoice::with('contact')->findOrFail($id), force: true);

        return $this->successResponse(new CatalogResource($reminder), __('sales.messages.reminder_queued'), Response::HTTP_CREATED);
    }
}
