<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Contracts\FeePaymentGateway;
use Modules\Associations\DTOs\FeePaymentRequest;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Enums\PaymentMethod;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Models\PaymentLink;
use Modules\Associations\Support\Money;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Online payment of an instalment. The amount and the tenant of a confirmation come from the stored link, never from
 * the callback, and a repeated callback changes nothing.
 */
final class PaymentLinkService
{
    public function __construct(
        private readonly FeePaymentGateway $gateway,
        private readonly FeePaymentService $payments,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{link: PaymentLink, url: string}
     */
    public function create(FeeInstalment $instalment): array
    {
        if (in_array($instalment->status, [InstalmentStatus::Paid, InstalmentStatus::Cancelled], true)) {
            throw AssociationException::conflict(__('associations.instalment_closed'));
        }

        $expiresAt = CarbonImmutable::now()->addHours((int) config('associations.payment_link_hours'));
        $amount = $instalment->outstanding();

        $data = $this->gateway->createLink(new FeePaymentRequest(
            reference: (string) Str::uuid7(),
            amount: $amount,
            currency: (string) ($this->tenantContext->getTenant()?->default_currency ?: 'EUR'),
            description: (string) $instalment->assignment->description,
            expiresAt: $expiresAt,
        ));

        $link = PaymentLink::create([
            'fee_instalment_id' => $instalment->id,
            'provider' => $this->gateway->provider(),
            'provider_reference' => $data->providerReference,
            'amount' => $amount,
            'status' => PaymentLink::Pending,
            'expires_at' => $expiresAt,
        ]);

        return ['link' => $link, 'url' => $data->url];
    }

    public function handleCallback(Request $callback): PaymentLink
    {
        $confirmation = $this->gateway->confirmation($callback);

        $link = PaymentLink::withoutGlobalScopes()
            ->where('provider', $this->gateway->provider())
            ->where('provider_reference', $confirmation->providerReference)
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
}
