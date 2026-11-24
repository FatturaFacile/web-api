<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\PaymentLink;
use Modules\Associations\Support\Money;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\DTOs\PaymentLinkRequest;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Services\PaymentGatewayResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Online payment of an instalment, through the payment drivers shared with the invoice payment links. The amount and the
 * tenant of a confirmation come from the stored link, never from the callback, and a repeated callback changes nothing.
 */
final class PaymentLinkService
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly FeePaymentService $payments,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The credentials of the driver of the fees. The local driver needs a webhook secret: without a configured one it
     * uses a secret derived from the application key, which a client cannot compute.
     *
     * @return array<string, string>
     */
    public static function credentials(): array
    {
        $configured = array_filter((array) config('associations.payment_credentials'), static fn (mixed $value): bool => is_string($value) && $value !== '');

        return $configured + ['webhook_secret' => hash_hmac('sha256', 'associations.local-payments', (string) config('app.key'))];
    }

    /**
     * @return array{link: PaymentLink, url: string}
     */
    public function create(FeeInstalment $instalment): array
    {
        if (in_array($instalment->status, [InstalmentStatus::Paid, InstalmentStatus::Cancelled], true)) {
            throw AssociationException::conflict(__('associations.instalment_closed'));
        }

        $gateway = $this->gateway();
        $expiresAt = CarbonImmutable::now()->addHours((int) config('associations.payment_link_hours'));
        $amount = $instalment->outstanding();

        try {
            $created = $gateway->createLink(new PaymentLinkRequest(
                reference: (string) Str::uuid7(),
                amount: $amount,
                currency: (string) ($this->tenantContext->getTenant()?->default_currency ?: 'EUR'),
                description: (string) $instalment->assignment->description,
                expiresAt: $expiresAt,
            ), self::credentials());
        } catch (PaymentGatewayException $e) {
            throw new AssociationException($e->getMessage());
        }

        $link = PaymentLink::create([
            'fee_instalment_id' => $instalment->id,
            'provider' => $gateway->name(),
            'provider_reference' => $created->reference,
            'amount' => $amount,
            'status' => PaymentLink::Pending,
            'expires_at' => $expiresAt,
        ]);

        return ['link' => $link, 'url' => $created->url];
    }

    /**
     * @return PaymentLink|null null when the call concerns no payment outcome
     */
    public function handleCallback(Request $callback): ?PaymentLink
    {
        $gateway = $this->gateway();

        try {
            $confirmation = $gateway->parseWebhook($callback, self::credentials());
        } catch (PaymentGatewayException) {
            throw new AssociationException('The payment confirmation is not signed.', Response::HTTP_FORBIDDEN);
        }

        if ($confirmation === null) {
            return null;
        }

        $link = PaymentLink::withoutGlobalScopes()
            ->where('provider', $gateway->name())
            ->where('provider_reference', $confirmation->reference)
            ->first() ?? throw new NotFoundHttpException('Unknown payment reference.');

        return $this->tenantContext->runWithTenant(Tenant::query()->findOrFail($link->tenant_id), fn (): PaymentLink => DB::transaction(function () use ($link, $confirmation): PaymentLink {
            /** @var PaymentLink $locked */
            $locked = PaymentLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentLink::Pending) {
                return $locked;
            }

            if (! $confirmation->paid) {
                $locked->update(['status' => PaymentLink::Failed]);

                return $locked;
            }

            if (Money::compare($confirmation->amount, Money::normalize((string) $locked->amount)) !== 0) {
                throw new AssociationException(__('associations.payment_amount_mismatch'));
            }

            $payment = $this->payments->record($locked->instalment, [
                'amount' => (string) $locked->amount,
                'paid_on' => CarbonImmutable::today()->toDateString(),
                'method' => PaymentMethod::Online,
                'reference' => mb_substr($locked->provider_reference, 0, 100),
            ]);

            $locked->update(['status' => PaymentLink::Paid, 'paid_at' => now(), 'fee_payment_id' => $payment->id]);

            return $locked;
        }));
    }

    private function gateway(): PaymentLinkGateway
    {
        return $this->gateways->driver((string) config('associations.payment_driver'));
    }
}
