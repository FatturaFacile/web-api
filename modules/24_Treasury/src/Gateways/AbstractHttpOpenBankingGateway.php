<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\OpenBankingGateway;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;

/**
 * Account information and payment initiation over a provider's REST API. The provider specific parts (config key,
 * request bodies, the shape of balances and movements) are the template methods of the subclasses; authentication is
 * an OAuth2 client-credentials token fetched from the configured token URL, the consent id travels in `Consent-ID`.
 *
 * Every failure of the provider (network, status, malformed answer) becomes an OpenBankingUnavailableException with the
 * provider's reason, so the callers never see a payment or a balance that was not really returned.
 */
abstract class AbstractHttpOpenBankingGateway implements OpenBankingGateway
{
    /**
     * Key of the provider in `config/open_banking.php`.
     */
    abstract protected function configKey(): string;

    /**
     * @param  array<string, mixed>  $json
     * @return array{currency: string, balance: string}
     */
    abstract protected function parseBalance(array $json): array;

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{external_id: string, booked_at: string, amount: string, currency: string, description: string, counterpart_name?: ?string, counterpart_iban?: ?string, reference?: ?string}>
     */
    abstract protected function parseTransactions(array $json): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function consentBody(string $iban): array;

    public function name(): string
    {
        return $this->configKey();
    }

    public function isAvailable(): bool
    {
        return filled($this->config('base_url')) && filled($this->config('client_id')) && filled($this->config('client_secret'));
    }

    public function requestConsent(string $iban): array
    {
        $json = $this->send(fn (PendingRequest $http): Response => $http->post($this->path('consents'), $this->consentBody($iban)));
        $id = (string) ($json['consentId'] ?? $json['consent_id'] ?? '');

        if ($id === '') {
            throw OpenBankingUnavailableException::providerFailure($this->configKey(), 'no consent id in the answer');
        }

        return [
            'external_consent_id' => $id,
            'access_token' => $id,
            'expires_at' => (string) ($json['validUntil'] ?? now()->addDays(90)->toIso8601String()),
            'status' => in_array(strtolower((string) ($json['consentStatus'] ?? 'valid')), ['valid', 'received', 'active'], true) ? 'active' : 'pending',
        ];
    }

    public function accountBalance(string $accessToken, string $iban): array
    {
        $account = $this->resourceId($accessToken, $iban);
        $json = $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['Consent-ID' => $accessToken])->get($this->path('balances', $account)));

        return ['iban' => $iban] + $this->parseBalance($json);
    }

    public function transactions(string $accessToken, string $iban, string $from, string $to): array
    {
        $account = $this->resourceId($accessToken, $iban);
        $json = $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['Consent-ID' => $accessToken])
            ->get($this->path('transactions', $account), ['dateFrom' => $from, 'dateTo' => $to, 'bookingStatus' => 'booked']));

        return $this->parseTransactions($json);
    }

    public function initiatePayment(string $accessToken, array $payment): array
    {
        $json = $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['Consent-ID' => $accessToken])->post($this->path('payments'), [
            'instructedAmount' => ['currency' => $payment['currency'], 'amount' => $payment['amount']],
            'debtorAccount' => ['iban' => $payment['debtor_iban']],
            'creditorAccount' => ['iban' => $payment['creditor_iban']],
            'creditorName' => $payment['creditor_name'],
            'remittanceInformationUnstructured' => $payment['remittance_information'],
        ]));

        return [
            'external_payment_id' => (string) ($json['paymentId'] ?? $json['payment_id'] ?? ''),
            'status' => strtolower((string) ($json['transactionStatus'] ?? 'received')),
        ];
    }

    public function paymentStatus(string $accessToken, string $externalPaymentId): string
    {
        $json = $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['Consent-ID' => $accessToken])->get($this->path('payments').'/'.$externalPaymentId.'/status'));

        return strtolower((string) ($json['transactionStatus'] ?? 'unknown'));
    }

    protected function config(string $key): mixed
    {
        return config('open_banking.'.$this->configKey().'.'.$key);
    }

    protected function path(string $name, ?string $account = null): string
    {
        return str_replace('{account}', (string) $account, (string) $this->config("paths.{$name}"));
    }

    /**
     * The provider's resource id of the account with this IBAN.
     */
    private function resourceId(string $accessToken, string $iban): string
    {
        $json = $this->send(fn (PendingRequest $http): Response => $http->withHeaders(['Consent-ID' => $accessToken])->get($this->path('accounts')));

        foreach ((array) ($json['accounts'] ?? $json) as $account) {
            if (is_array($account) && strtoupper((string) ($account['iban'] ?? '')) === strtoupper($iban)) {
                return (string) ($account['resourceId'] ?? $account['id'] ?? $account['iban']);
            }
        }

        throw OpenBankingUnavailableException::providerFailure($this->configKey(), 'the account is not part of the consent');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if (! $this->isAvailable()) {
            throw OpenBankingUnavailableException::providerRequired();
        }

        try {
            $response = $call($this->client());
            $response->throw();
            $json = $response->json();
        } catch (ConnectionException|RequestException $e) {
            throw OpenBankingUnavailableException::providerFailure($this->configKey(), $e->getMessage());
        }

        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config('base_url'), '/'))
            ->timeout((int) $this->config('timeout'))
            ->acceptJson()
            ->withToken($this->token())
            ->withHeaders(['X-Request-ID' => (string) Str::uuid()]);
    }

    private function token(): string
    {
        $tokenUrl = (string) $this->config('token_url');

        if ($tokenUrl === '') {
            return (string) $this->config('client_secret');
        }

        try {
            $response = Http::asForm()->timeout((int) $this->config('timeout'))->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
            ])->throw();
        } catch (ConnectionException|RequestException $e) {
            throw OpenBankingUnavailableException::providerFailure($this->configKey(), $e->getMessage());
        }

        return (string) $response->json('access_token');
    }
}
