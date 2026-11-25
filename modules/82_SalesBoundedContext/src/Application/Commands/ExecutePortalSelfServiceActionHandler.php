<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SalesBoundedContext\Domain\Enums\PortalPaymentProvider;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Domain\ValueObjects\FiscalIdentity;
use Modules\SalesBoundedContext\Events\PortalOnlinePaymentSettledEvent;
use Modules\SalesBoundedContext\Models\SbcCustomerDossier;
use Modules\SalesBoundedContext\Models\SbcPortalTransaction;

final readonly class ExecutePortalSelfServiceActionHandler
{
    public function handle(ExecutePortalSelfServiceActionCommand $command): SbcPortalTransaction
    {
        $data = $command->payload;
        $type = (string) ($data['transaction_type'] ?? 'online_payment');
        $provider = PortalPaymentProvider::from((string) ($data['payment_provider'] ?? 'twint'));
        $currency = strtoupper((string) ($data['currency'] ?? 'CHF'));
        $amount = DecimalMoney::of((float) ($data['amount'] ?? 0), $currency);

        $tx = DB::transaction(function () use ($command, $data, $type, $provider, $currency, $amount): SbcPortalTransaction {
            $openBalanceAfter = DecimalMoney::of(0, $currency);

            if (isset($data['dossier_id']) && $type === 'online_payment') {
                $dossier = SbcCustomerDossier::query()
                    ->where('tenant_id', $command->tenantId)
                    ->find($data['dossier_id']);

                if ($dossier !== null) {
                    $newExposure = max(0.0, (float) $dossier->current_exposure - $amount->toFloat());
                    $newOverdue = max(0.0, (float) $dossier->overdue_balance - $amount->toFloat());
                    $dossier->current_exposure = number_format($newExposure, 2, '.', '');
                    $dossier->overdue_balance = number_format($newOverdue, 2, '.', '');
                    $dossier->save();
                    $openBalanceAfter = DecimalMoney::of($newExposure, $currency);
                }
            }

            return SbcPortalTransaction::create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'ddt_invoice_id' => $data['ddt_invoice_id'] ?? null,
                'transaction_code' => (string) ($data['transaction_code'] ?? 'PRT-'.strtoupper(Str::random(6))),
                'transaction_type' => $type,
                'payment_provider' => $provider,
                'amount' => $amount->roundHalfUp(),
                'currency' => $currency,
                'open_balance_after' => $openBalanceAfter->roundHalfUp(),
                'qr_bill_reference' => $provider === PortalPaymentProvider::QrBill || $type === 'qr_bill_download'
                    ? FiscalIdentity::buildSwissQrReference((string) random_int(10000000, 99999999))
                    : null,
                'payment_reference' => $type === 'online_payment'
                    ? strtoupper($provider->value).'-'.strtoupper(Str::random(10))
                    : null,
                'support_subject' => $data['support_subject'] ?? null,
                'support_status' => $type === 'support_ticket' ? 'open' : null,
                'settled_at' => $type === 'online_payment' ? now() : null,
            ]);
        });

        if ($type === 'online_payment') {
            PortalOnlinePaymentSettledEvent::dispatch(
                $command->tenantId,
                (string) $tx->id,
                $provider->value,
                $amount->roundHalfUp(),
                $currency,
            );
        }

        return $tx;
    }
}
