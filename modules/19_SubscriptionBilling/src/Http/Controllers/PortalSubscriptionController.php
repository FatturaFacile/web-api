<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only view of the subscriptions of a customer through the magic link of the customer portal.
 */
final class PortalSubscriptionController extends Controller
{
    public function __construct(private readonly PortalTokenService $tokens) {}

    /**
     * The subscriptions of the customer of the link, with their issued invoices.
     */
    #[OA\Get(path: '/api/v1/portal/subscriptions/{token}', operationId: 'listPortalSubscriptions', summary: 'List portal subscriptions', description: 'Subscriptions of the customer of a dashboard portal link (a link scoped to another record gives no access), with plan, status, next billing date, term and their issued invoices.', security: [], tags: ['Customer Portal'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPortalToken')], responses: [
        new OA\Response(response: 200, description: 'Subscriptions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingPortalSubscription'))])),
        new OA\Response(response: 403, description: 'The link does not give access to the subscriptions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Unknown link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 410, description: 'The link has expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(string $token): JsonResponse
    {
        $access = $this->tokens->resolveToken($token);
        abort_unless($access->scope_type === null, Response::HTTP_FORBIDDEN, 'This portal link does not give access to these records.');

        $subscriptions = Subscription::withoutGlobalScopes()
            ->with(['plan' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $access->tenant_id)
            ->where('customer_id', $access->contact_id)
            ->whereNull('deleted_at')
            ->orderByDesc('start_date')
            ->get();

        $invoiceIds = InvoiceSchedule::withoutGlobalScopes()->whereIn('subscription_id', $subscriptions->pluck('id'))->whereNotNull('invoice_id')->where('status', 'invoiced')->get(['subscription_id', 'invoice_id']);
        $invoices = SalesInvoice::withoutGlobalScopes()->whereIn('id', $invoiceIds->pluck('invoice_id'))->where('status', 'posted')->get()->keyBy('id');

        $data = $subscriptions->map(fn (Subscription $subscription): array => [
            'id' => $subscription->id,
            'subscription_number' => $subscription->subscription_number,
            'name' => $subscription->name,
            'plan' => $subscription->plan?->name,
            'status' => $subscription->status,
            'currency' => $subscription->currency,
            'next_billing_date' => $subscription->isCancelled() ? null : $subscription->current_period_start?->toDateString(),
            'term_end_date' => $subscription->term_end_date?->toDateString(),
            'auto_renew' => (bool) $subscription->auto_renew,
            'invoices' => $invoiceIds->where('subscription_id', $subscription->id)->unique('invoice_id')
                ->map(fn (InvoiceSchedule $schedule): ?SalesInvoice => $invoices->get($schedule->invoice_id))
                ->filter()
                ->map(fn (SalesInvoice $invoice): array => [
                    'invoice_number' => $invoice->invoice_number,
                    'date' => CarbonImmutable::parse((string) $invoice->getRawOriginal('date'))->toDateString(),
                    'total_gross' => (string) $invoice->getRawOriginal('total_gross'),
                    'payment_status' => $invoice->payment_status,
                ])->values()->all(),
        ])->values()->all();

        $this->tokens->logActivity($access, 'subscriptions_viewed');

        return response()->json(['data' => $data]);
    }
}
