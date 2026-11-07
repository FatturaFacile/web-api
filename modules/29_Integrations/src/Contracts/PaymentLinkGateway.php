<?php

declare(strict_types=1);

namespace Modules\Integrations\Contracts;

use Illuminate\Http\Request;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Models\PaymentLink;

interface PaymentLinkGateway
{
    public function name(): string;

    /**
     * Asks the provider for a hosted payment page. No card data ever reaches this application.
     *
     * @param  array<string, string>  $credentials
     * @return array{reference: string, url: string}
     *
     * @throws PaymentGatewayException when the provider refuses
     */
    public function createLink(PaymentLink $link, string $description, array $credentials): array;

    /**
     * Reads a webhook call, after checking that it really comes from the provider.
     *
     * @param  array<string, string>  $credentials
     * @return PaymentEvent|null null for events that do not concern a completed payment
     *
     * @throws PaymentGatewayException when the signature is missing or wrong
     */
    public function parseWebhook(Request $request, array $credentials): ?PaymentEvent;
}
