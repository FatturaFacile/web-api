<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\Portal\PortalDeliveryNoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PortalAccessToken;
use App\Models\Quote;
use App\Support\Portal\PortalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PortalDashboardController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/portal/me',
        operationId: 'getPortalDashboard',
        description: 'Profile and dashboard of the signed-in customer: open balance per currency, overdue invoices, quotes waiting for an answer and the latest shipments.',
        summary: 'Get my portal dashboard',
        security: [['portalBearer' => []]],
        tags: ['Customer Portal'],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Not signed in', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var PortalAccessToken $session */
        $session = $request->attributes->get('portal_token');
        $contact = Contact::query()->findOrFail($session->contact_id);

        $open = Invoice::where('contact_id', $contact->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->where('invoice_type', '!=', 'proforma')
            ->get();

        $balance = [];
        $overdue = 0;

        foreach ($open as $invoice) {
            $currency = (string) ($invoice->currency ?? 'EUR');
            $balance[$currency] = bcadd($balance[$currency] ?? '0', $invoice->outstandingAmount(), 2);

            if (PortalDate::isPast($invoice->due_date)) {
                $overdue++;
            }
        }

        $pendingQuotes = Quote::where('contact_id', $contact->id)->where('status', 'sent')->latest('date')->limit(5)->get(['id', 'quote_number', 'date', 'total_gross', 'currency']);
        $shipments = DeliveryNote::where('contact_id', $contact->id)->whereNull('cancelled_at')->latest('date')->limit(5)->get();

        return $this->successResponse(data: [
            'customer' => [
                'id' => $contact->id,
                'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
                'email' => $contact->email,
                'country' => $contact->country,
            ],
            'session_expires_at' => PortalDate::iso($session->expires_at),
            'open_balance' => collect($balance)->map(fn (string $amount, string $currency): array => ['currency' => $currency, 'amount' => $amount])->values()->all(),
            'open_invoices' => $open->count(),
            'overdue_invoices' => $overdue,
            'pending_quotes' => $pendingQuotes->map(fn (Quote $quote): array => [
                'id' => $quote->id,
                'quote_number' => $quote->quote_number,
                'date' => PortalDate::day($quote->date),
                'total_gross' => (float) $quote->total_gross,
                'currency' => $quote->currency ?? 'EUR',
            ])->all(),
            'recent_shipments' => PortalDeliveryNoteResource::collection($shipments)->resolve(),
        ], message: 'Portal dashboard retrieved successfully');
    }
}
