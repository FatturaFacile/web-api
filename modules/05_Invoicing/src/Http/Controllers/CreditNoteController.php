<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\IssueCreditNoteAction;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Http\Requests\IssueCreditNoteRequest;
use Modules\Invoicing\Http\Resources\SalesInvoiceResource;
use Modules\Invoicing\Models\SalesInvoice;

final class CreditNoteController extends Controller
{
    public function store(
        IssueCreditNoteRequest $request,
        IssueCreditNoteAction $action
    ): JsonResponse {
        $validated = $request->validated();
        $items = $validated['items'];
        $originalId = $validated['original_invoice_id'];
        unset($validated['items']);

        $originalInvoice = SalesInvoice::findOrFail($originalId);
        $userId = (string) ($request->user()?->id ?? 'system');
        $validated['created_by'] = $userId;

        $creditNote = $action->execute($originalInvoice, $validated, $items);

        return response()->json(new SalesInvoiceResource($creditNote), 201);
    }

    public function apply(
        string $creditNoteId,
        Request $request,
        InvoicingServiceInterface $service
    ): JsonResponse {
        $creditNote = SalesInvoice::findOrFail($creditNoteId);
        $targetId = (string) $request->input('target_invoice_id');
        $targetInvoice = SalesInvoice::findOrFail($targetId);
        $amount = (float) $request->input('amount');
        $userId = (string) ($request->user()?->id ?? 'system');

        $application = $service->applyCreditNote($creditNote, $targetInvoice, $amount, $userId);

        return response()->json([
            'message' => 'Credit note applied successfully',
            'application_id' => $application->id,
            'applied_amount' => $application->applied_amount,
            'target_invoice_status' => $targetInvoice->fresh()->payment_status,
        ]);
    }
}
