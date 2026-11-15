<?php

declare(strict_types=1);

namespace Modules\Contracts\Services;

use Illuminate\Support\Str;
use Modules\Contracts\Contracts\SignatureProvider;
use Modules\Contracts\Models\ContractSignatureRequest;

/**
 * Built-in simple provider: no external call, it issues one random token per signer. Whoever holds the token confirms
 * the signature; the company hands the token to the signer.
 */
final class InternalSignatureProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'internal';
    }

    public function dispatch(ContractSignatureRequest $request): array
    {
        $tokens = [];

        foreach ($request->signers as $signer) {
            $tokens[(string) $signer->getKey()] = Str::random(48);
        }

        return ['reference' => 'internal-'.$request->getKey(), 'tokens' => $tokens];
    }

    public function cancel(ContractSignatureRequest $request): void {}
}
