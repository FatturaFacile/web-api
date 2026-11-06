<?php

declare(strict_types=1);

namespace Modules\Associations\Contracts;

use Illuminate\Http\Request;
use Modules\Associations\DTOs\FeePaymentConfirmation;
use Modules\Associations\DTOs\FeePaymentLinkData;
use Modules\Associations\DTOs\FeePaymentRequest;
use Modules\Associations\Exceptions\AssociationException;

/**
 * An online payment provider for the fees. Deliberately small, to be unified at integration with the payment link
 * contract being added for the invoices.
 */
interface FeePaymentGateway
{
    public function provider(): string;

    public function createLink(FeePaymentRequest $request): FeePaymentLinkData;

    /**
     * Checks the authenticity of a provider callback and reads it.
     *
     * @throws AssociationException when the callback cannot be trusted
     */
    public function confirmation(Request $callback): FeePaymentConfirmation;
}
