<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\PassiveInvoice;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Contracts\OpenBankingGateway;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;
use Modules\Treasury\Gateways\LocalOpenBankingGateway;
use Modules\Treasury\Gateways\RefusingOpenBankingGateway;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class OpenBankingService
{
    public function __construct(private IbanValidator $ibans) {}

    public function gateway(): OpenBankingGateway
    {
        return config('open_banking.driver') === 'local' ? new LocalOpenBankingGateway : new RefusingOpenBankingGateway;
    }

    /**
     * @throws OpenBankingUnavailableException
     */
    public function createConsent(string $iban, string $userId): OpenBankingConsent
    {
        $gateway = $this->gateway();
        $normalized = $this->ibans->normalize($iban);
        $consent = $gateway->requestConsent($normalized);

        return OpenBankingConsent::create([
            'driver' => $gateway->name(),
            'iban' => $normalized,
            'external_consent_id' => $consent['external_consent_id'],
            'access_token' => $consent['access_token'],
            'status' => $consent['status'],
            'expires_at' => $consent['expires_at'],
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array{balance: array{iban: string, currency: string, balance: string}, transactions: list<array{external_id: string, booked_at: string, amount: string, currency: string, description: string}>}
     *
     * @throws OpenBankingUnavailableException
     * @throws ConflictHttpException when the consent is no longer active
     */
    public function accountInformation(OpenBankingConsent $consent, string $from, string $to): array
    {
        $this->assertActive($consent);
        $gateway = $this->gateway();

        return [
            'balance' => $gateway->accountBalance((string) $consent->access_token, $consent->iban),
            'transactions' => $gateway->transactions((string) $consent->access_token, $consent->iban, $from, $to),
        ];
    }

    /**
     * A payment of a supplier invoice obeys the approval: an invoice waiting or rejected never reaches the provider.
     *
     * @param  array{creditor_iban: string, creditor_name: string, amount: string, currency: string, remittance_information?: ?string, passive_invoice_id?: ?string}  $data
     *
     * @throws OpenBankingUnavailableException
     * @throws ConflictHttpException when the consent is no longer active
     * @throws ValidationException when the invoice is not approved for payment
     */
    public function initiatePayment(OpenBankingConsent $consent, array $data, string $userId): OpenBankingPayment
    {
        $this->assertActive($consent);

        if (! empty($data['passive_invoice_id'])) {
            $invoice = PassiveInvoice::query()->findOrFail($data['passive_invoice_id']);

            if ($invoice->isPaymentBlocked()) {
                throw ValidationException::withMessages(['passive_invoice_id' => __('treasury_errors.payment_export.not_approved', ['number' => $invoice->invoice_number])]);
            }
        }

        $creditorIban = $this->ibans->normalize($data['creditor_iban']);
        $receipt = $this->gateway()->initiatePayment((string) $consent->access_token, [
            'debtor_iban' => $consent->iban,
            'creditor_iban' => $creditorIban,
            'creditor_name' => $data['creditor_name'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'remittance_information' => $data['remittance_information'] ?? null,
        ]);

        return OpenBankingPayment::create([
            'consent_id' => $consent->id,
            'passive_invoice_id' => $data['passive_invoice_id'] ?? null,
            'debtor_iban' => $consent->iban,
            'creditor_iban' => $creditorIban,
            'creditor_name' => $data['creditor_name'],
            'amount' => (string) $data['amount'],
            'currency' => $data['currency'],
            'remittance_information' => $data['remittance_information'] ?? null,
            'external_payment_id' => $receipt['external_payment_id'],
            'status' => $receipt['status'],
            'initiated_by' => $userId,
        ]);
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertActive(OpenBankingConsent $consent): void
    {
        if (! $consent->isActive()) {
            throw new ConflictHttpException(__('treasury_errors.open_banking.consent_inactive'));
        }
    }
}
