<?php

declare(strict_types=1);

namespace Modules\Associations\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Associations\Contracts\FeePaymentGateway;
use Modules\Associations\DTOs\FeePaymentConfirmation;
use Modules\Associations\DTOs\FeePaymentLinkData;
use Modules\Associations\DTOs\FeePaymentRequest;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Support\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * The driver without an external provider, for development, tests and offline setups. A confirmation counts only with
 * the signature of the installation, which a client cannot compute.
 */
final class LocalFeePaymentGateway implements FeePaymentGateway
{
    public const string SignatureHeader = 'X-Association-Signature';

    public function provider(): string
    {
        return 'local';
    }

    public function createLink(FeePaymentRequest $request): FeePaymentLinkData
    {
        $reference = 'LOCAL-'.Str::upper(Str::random(24));

        return new FeePaymentLinkData($reference, rtrim((string) config('app.url'), '/').'/api/v1/association-payments/local/'.$reference);
    }

    public function confirmation(Request $callback): FeePaymentConfirmation
    {
        $reference = (string) $callback->input('reference');
        $amount = Money::normalize((string) $callback->input('amount'));
        $status = (string) $callback->input('status');

        if (! hash_equals(self::signature($reference, $amount, $status), (string) $callback->header(self::SignatureHeader))) {
            throw new AssociationException('The payment confirmation is not signed.', Response::HTTP_FORBIDDEN);
        }

        return new FeePaymentConfirmation($reference, $amount, $status === 'paid');
    }

    public static function signature(string $reference, string $amount, string $status): string
    {
        return hash_hmac('sha256', "{$reference}|{$amount}|{$status}", 'associations.local-payments|'.config('app.key'));
    }
}
