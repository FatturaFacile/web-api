<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\SwissEbill\Application\Dto\GatewayConnectionResult;
use Modules\SwissEbill\Application\Dto\GatewayReceipt;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Application\Dto\ParticipantRecord;
use Modules\SwissEbill\Application\Ports\EbillGatewayInterface;
use Modules\SwissEbill\Domain\Enums\EbillProtocol;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Models\EbillSetting;
use SimpleXMLElement;

/**
 * Network access point client for SIX Paynet (direct) and PostFinance eBill, over REST (JSON answers, XML invoice body) or
 * SOAP (ebInterface envelope). The invoice body is signed with the private key of the company when one is configured.
 * Transport failures, HTTP 429 and 5xx are reported as transient gateway errors.
 */
final class HttpEbillGateway implements EbillGatewayInterface
{
    private const int TIMEOUT_SECONDS = 20;

    public function testConnection(EbillSetting $setting): GatewayConnectionResult
    {
        $started = hrtime(true);

        try {
            $data = $this->call($setting, 'ping', 'GET', 'v1/ping');
        } catch (EbillGatewayException $e) {
            return new GatewayConnectionResult($e->gatewayStatus !== null, false, $this->elapsedMs($started), $e->getMessage());
        }

        return new GatewayConnectionResult(true, true, $this->elapsedMs($started), (string) ($data['message'] ?? 'OK'), isset($data['environment']) ? (string) $data['environment'] : null);
    }

    public function lookupParticipant(EbillSetting $setting, ParticipantLookupCriteria $criteria): ?ParticipantRecord
    {
        try {
            $data = $this->call($setting, 'FindParticipant', 'GET', 'v1/participants', [$criteria->type => $criteria->value]);
        } catch (EbillGatewayException $e) {
            if ($e->gatewayStatus === 404) {
                return null;
            }

            throw $e;
        }

        return GatewayPayloadMapper::participant($data['participant'] ?? $data);
    }

    public function submit(EbillSetting $setting, GatewaySubmission $submission): GatewayReceipt
    {
        $data = $this->call($setting, 'SubmitInvoice', 'POST', 'v1/invoices', [], $submission->xml, [
            'Idempotency-Key' => $submission->idempotencyKey,
            'X-Biller-Id' => $submission->billerId,
            'X-Recipient-Id' => $submission->recipientId,
            'X-Payload-Sha256' => $submission->xmlSha256,
            'X-Payload-Signature' => $this->signature($setting, $submission->xml),
        ]);

        $transaction = (string) ($data['transaction_id'] ?? $data['transactionId'] ?? '');

        if ($transaction === '') {
            throw new EbillGatewayException('The gateway answered without a transaction id.', 502, 'invalid_response');
        }

        return new GatewayReceipt($transaction, CarbonImmutable::parse((string) ($data['submitted_at'] ?? $data['submittedAt'] ?? 'now')));
    }

    public function recall(EbillSetting $setting, string $sixTransactionId): void
    {
        $this->call($setting, 'RecallInvoice', 'POST', 'v1/invoices/'.rawurlencode($sixTransactionId).'/recall');
    }

    public function fetchStatus(EbillSetting $setting, string $sixTransactionId): array
    {
        $data = $this->call($setting, 'GetInvoiceEvents', 'GET', 'v1/invoices/'.rawurlencode($sixTransactionId).'/events');
        $events = [];

        foreach ((array) ($data['events'] ?? []) as $event) {
            try {
                $events[] = GatewayPayloadMapper::notification((array) $event, $sixTransactionId);
            } catch (InvalidArgumentException) {
                // An event type this version does not know is skipped, the next poll shows it again once supported.
                continue;
            }
        }

        usort($events, static fn ($a, $b): int => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function call(EbillSetting $setting, string $operation, string $method, string $path, array $query = [], ?string $body = null, array $headers = []): array
    {
        $base = rtrim((string) $setting->api_base_url, '/');

        if ($base === '') {
            throw new EbillGatewayException('The gateway URL is not configured.', null, 'not_configured');
        }

        $soap = $setting->channel_protocol === EbillProtocol::SoapEbinterface;

        try {
            $request = $this->request($setting, $base);

            if ($soap) {
                $response = $request->withHeaders(['SOAPAction' => $operation, 'Content-Type' => 'text/xml; charset=utf-8'] + $headers)
                    ->withBody($this->envelope($setting, $operation, $query, $body), 'text/xml')
                    ->post($base.'/soap');
            } else {
                $request = $request->withHeaders($headers);
                $url = $base.'/'.$path;
                $response = match ($method) {
                    'GET' => $request->get($url, $query),
                    default => $body !== null ? $request->withBody($body, 'application/xml')->post($url) : $request->post($url),
                };
            }
        } catch (ConnectionException $e) {
            throw new EbillGatewayException('The gateway cannot be reached: '.$e->getMessage(), null, 'network_error', true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return $soap ? $this->parseSoap($response) : (array) ($response->json() ?? []);
    }

    private function request(EbillSetting $setting, string $base): PendingRequest
    {
        $request = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson();

        if ($setting->channel_protocol === EbillProtocol::SoapEbinterface) {
            return $request;
        }

        return $request->withToken($this->token($setting, $base));
    }

    private function token(EbillSetting $setting, string $base): string
    {
        $key = 'ebill-token:'.$setting->id.':'.sha1((string) $setting->client_id);

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->asForm()->post($base.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $setting->client_id,
                'client_secret' => (string) $setting->client_secret,
            ]);
        } catch (ConnectionException $e) {
            throw new EbillGatewayException('The gateway cannot be reached: '.$e->getMessage(), null, 'network_error', true);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new EbillGatewayException('The gateway refused the credentials.', $response->status() ?: 401, 'invalid_credentials');
        }

        Cache::put($key, $response->json('access_token'), now()->addSeconds(max(60, (int) $response->json('expires_in', 600) - 30)));

        return (string) $response->json('access_token');
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function envelope(EbillSetting $setting, string $operation, array $query, ?string $body): string
    {
        $arguments = '';

        foreach ($query as $name => $value) {
            $arguments .= sprintf('<%1$s>%2$s</%1$s>', preg_replace('/[^A-Za-z0-9_]/', '', (string) $name), htmlspecialchars((string) $value, ENT_XML1));
        }

        if ($body !== null) {
            $arguments .= '<Invoice encoding="base64">'.base64_encode($body).'</Invoice>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:eb="urn:swiss-ebill:gateway:1">'
            .'<soap:Header><eb:Auth><eb:ClientId>'.htmlspecialchars((string) $setting->client_id, ENT_XML1).'</eb:ClientId>'
            .'<eb:ClientSecret>'.htmlspecialchars((string) $setting->client_secret, ENT_XML1).'</eb:ClientSecret></eb:Auth></soap:Header>'
            .'<soap:Body><eb:'.$operation.'>'.$arguments.'</eb:'.$operation.'></soap:Body></soap:Envelope>';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSoap(Response $response): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($response->body());
            $body = $xml->children('http://schemas.xmlsoap.org/soap/envelope/')->Body ?? null;
            $payload = $body !== null ? $body->children('urn:swiss-ebill:gateway:1') : null;
            $decoded = $payload !== null ? json_decode((string) json_encode($payload), true) : null;
        } catch (\Throwable) {
            throw new EbillGatewayException('The gateway answered with an unreadable SOAP message.', 502, 'invalid_response');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $first = is_array($decoded) ? (array) reset($decoded) : [];

        return array_key_exists('events', $first) && isset($first['events']['event']) ? ['events' => array_is_list((array) $first['events']['event']) ? $first['events']['event'] : [$first['events']['event']]] : $first;
    }

    private function failure(Response $response): EbillGatewayException
    {
        $code = $response->json('code') ?? $response->json('error');

        return new EbillGatewayException(
            'The gateway answered HTTP '.$response->status().(is_string($response->json('message')) ? ': '.$response->json('message') : '.'),
            $response->status(),
            is_string($code) ? $code : null,
        );
    }

    private function signature(EbillSetting $setting, string $xml): string
    {
        $key = $setting->private_key_pem;

        if ($key === null || $key === '') {
            return '';
        }

        $private = @openssl_pkey_get_private($key);

        if ($private === false || ! openssl_sign($xml, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new EbillGatewayException('The private key of the company cannot sign the payload.', null, 'signing_failed');
        }

        return base64_encode($signature);
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
