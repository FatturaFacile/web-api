<?php

declare(strict_types=1);

namespace Modules\Contracts\Contracts;

use Modules\Contracts\Models\ContractSignatureRequest;

/**
 * An e-signature provider. Implementations talk to the outside world, so they are only called outside database
 * transactions. The built-in "internal" provider needs no external service.
 */
interface SignatureProvider
{
    /**
     * Identifier stored on the request.
     */
    public function name(): string;

    /**
     * Hand the request to the provider and return its reference, plus a personal token per signer id when the provider
     * lets the company collect the signatures itself (providers that email the signers return no tokens).
     *
     * @return array{reference: string|null, tokens: array<string, string>}
     */
    public function dispatch(ContractSignatureRequest $request): array;

    /**
     * Withdraw the request at the provider.
     */
    public function cancel(ContractSignatureRequest $request): void;
}
