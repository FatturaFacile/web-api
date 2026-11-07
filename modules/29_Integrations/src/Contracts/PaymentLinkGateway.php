<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

use Illuminate\Http\Request;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\DTOs\PaymentLinkCreated;
use Modules\Integrations\DTOs\PaymentLinkRequest;
use Modules\Integrations\Exceptions\PaymentGatewayException;

/**
 * The single contract of the online payment providers, shared by the payment links of the invoices and by the fees of
 * the associations. A driver knows nothing of the document being paid: it opens a hosted payment page for an amount
 * and reads the provider's confirmation.
 */
interface PaymentLinkGateway
{
    public function name(): string;

    /**
     * Asks the provider for a hosted payment page. No card data ever reaches this application.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws PaymentGatewayException when the provider refuses
     */
    public function createLink(PaymentLinkRequest $request, array $credentials): PaymentLinkCreated;

    /**
     * Reads a webhook call, after checking that it really comes from the provider.
     *
     * @param  array<string, string>  $credentials
     * @return PaymentEvent|null null for events that do not concern the outcome of a payment
     *
     * @throws PaymentGatewayException when the signature is missing or wrong
     */
    public function parseWebhook(Request $request, array $credentials): ?PaymentEvent;
}
