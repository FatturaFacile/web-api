<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Shipping;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\ShippingCarrierDriver;
use Modules\Inventory\DTOs\CarrierBooking;
use Modules\Inventory\DTOs\CarrierTrackingEvent;
use Modules\Inventory\Exceptions\CarrierException;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\Shipment;
use Modules\Inventory\Models\ShippingCarrier;

/**
 * DPD Switzerland, through its public Label Print Shipment API (label-print-docs.dpd.ch): JWT login, shipments with
 * label, tracking by parcel number. Other DPD countries run different APIs and are not covered.
 *
 * The carrier credentials hold `username`, `password`, `sender` (name, street, zip_code, city, country_code) and
 * optionally `service_code` and `base_url`, for the staging host. `contract_number` is DPD's invoicing number. Domestic
 * shipments only: an international one needs customs data the shipment does not carry.
 */
final class DpdSwitzerlandDriver implements ShippingCarrierDriver
{
    private const string PRODUCTION_URL = 'https://label-print-shipments.dpd.ch';

    private const array DOMESTIC_COUNTRIES = ['CH', 'LI'];

    private const int ADDRESS_LINE_LENGTH = 35;

    private const int REASON_LENGTH = 120;

    private const int TIMEOUT_SECONDS = 30;

    /** The DPD status codes, as the steps of this application. */
    private const array STATUS_MAP = [
        'ACCEPTED' => 'picked_up',
        'AT_SENDING_DEPOT' => 'in_transit',
        'ON_THE_ROAD' => 'in_transit',
        'AT_DELIVERY_DEPOT' => 'out_for_delivery',
        'DELIVERED' => 'delivered',
    ];

    public function book(ShippingCarrier $carrier, Shipment $shipment): CarrierBooking
    {
        $credentials = $this->credentials($carrier);
        $recipient = $shipment->recipient;

        if (! in_array($recipient['country'] ?? '', self::DOMESTIC_COUNTRIES, true)) {
            throw new StockRuleViolation('This driver books domestic DPD shipments only: customs data is not available.');
        }

        $parcels = max(1, (int) $shipment->packages_count);
        $service = (string) ($credentials['service_code'] ?? 'B2B');
        $grams = (int) bcmul((string) ($shipment->gross_weight_kg ?? '0'), '1000', 0);
        $share = (int) ceil($grams / $parcels);

        $response = $this->send($carrier, fn (string $token): Response => $this->http()->withToken($token)->post($this->url($carrier, '/api/v1/shipments'), [[
            'invoicingNumber' => (string) $carrier->contract_number,
            'numberOfParcels' => $parcels,
            'clientSoftware' => 'FatturaFacile',
            'clientVersion' => '1.0',
            'serviceCode' => $service,
            'sender' => $this->address($credentials['sender']),
            'receiver' => $this->address([
                'name' => $recipient['name'],
                'country_code' => $recipient['country'],
                'zip_code' => $recipient['postal_code'],
                'city' => $recipient['city'],
                'street' => $recipient['address'],
                'phone' => $recipient['phone'] ?? null,
                'email' => $recipient['email'] ?? null,
            ]),
            'parcelInfo' => array_fill(0, $parcels, ['serviceCode' => $service, 'weight' => $share]),
            'printOptions' => ['paperSize' => 'A6', 'printerLanguage' => 'PDF'],
        ]]));

        $created = $response->json('success.0');
        $trackingNumber = $created['parcelNumbers'][0]['number'] ?? null;
        $label = $response->json('label');

        if (! $response->successful() || $trackingNumber === null || ! is_string($label) || $label === '') {
            throw new CarrierException('DPD refused the shipment: '.$this->reasonOf($response));
        }

        return new CarrierBooking(
            trackingNumber: (string) $trackingNumber,
            labelFormat: 'pdf',
            labelContent: $label,
            carrierReference: $created['tracingId'] ?? $response->json('tracingId'),
        );
    }

    public function track(ShippingCarrier $carrier, Shipment $shipment): array
    {
        $response = $this->send($carrier, fn (string $token): Response => $this->http()->withToken($token)
            ->get($this->url($carrier, '/api/v1/tracking/'.rawurlencode((string) $shipment->tracking_number)), ['lang' => 'it_CH']));

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new CarrierException('DPD tracking failed: '.$this->reasonOf($response));
        }

        $events = [];

        foreach ((array) $response->json('statusInfo') as $status) {
            $name = self::STATUS_MAP[$status['name'] ?? ''] ?? null;

            if ($name === null || ($status['hasBeenReached'] ?? false) !== true || empty($status['date'])) {
                continue;
            }

            $events[] = new CarrierTrackingEvent(
                status: $name,
                occurredAt: CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $status['date'], 'Europe/Zurich')->utc()->toIso8601String(),
                description: $status['displayName'] ?? null,
                location: empty($status['location']) ? null : (string) $status['location'],
            );
        }

        return $events;
    }

    /**
     * Logs in and runs the call. The API sets no refresh method, and a token lives for hours: a fresh login per call
     * costs little at the volume of a label desk.
     *
     * @param  callable(string): Response  $call
     */
    private function send(ShippingCarrier $carrier, callable $call): Response
    {
        $credentials = $this->credentials($carrier);

        try {
            $login = $this->http()->post($this->url($carrier, '/api/v1/login'), ['username' => $credentials['username'], 'password' => $credentials['password']]);

            if (! $login->successful() || ! is_string($login->json('token'))) {
                throw new CarrierException('DPD refused the login of carrier ['.$carrier->code.'].');
            }

            return $call($login->json('token'));
        } catch (ConnectionException) {
            throw new CarrierException('DPD cannot be reached.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(ShippingCarrier $carrier): array
    {
        $credentials = (array) $carrier->credentials;

        foreach (['username', 'password', 'sender'] as $key) {
            if (empty($credentials[$key])) {
                throw new StockRuleViolation("Carrier [{$carrier->code}] has no [{$key}] in its credentials.");
            }
        }

        if (empty($carrier->contract_number)) {
            throw new StockRuleViolation("Carrier [{$carrier->code}] has no contract number, which DPD uses as invoicing number.");
        }

        return $credentials;
    }

    private function url(ShippingCarrier $carrier, string $path): string
    {
        $base = (string) (((array) $carrier->credentials)['base_url'] ?? self::PRODUCTION_URL);

        // A stored base URL is checked again here: it may predate the validation, and only https is acceptable.
        if (! str_starts_with(strtolower($base), 'https://') || ! app(OutboundUrlGuard::class)->isPublicUrl($base)) {
            throw new CarrierException('The DPD base URL of carrier ['.$carrier->code.'] is not a public https address.');
        }

        return rtrim($base, '/').$path;
    }

    /**
     * Every call goes through the outbound guard: public addresses only, pinned, no redirects.
     */
    private function http(): PendingRequest
    {
        return app(OutboundUrlGuard::class)->protect(
            Http::acceptJson()->timeout(self::TIMEOUT_SECONDS),
            static fn (UnsafeOutboundUrlException $exception): CarrierException => new CarrierException('The DPD address is not a public address.'),
        );
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, string>
     */
    private function address(array $address): array
    {
        return array_filter([
            'name' => mb_substr((string) ($address['name'] ?? ''), 0, self::ADDRESS_LINE_LENGTH),
            'countryCode' => (string) ($address['country_code'] ?? ''),
            'zipCode' => (string) ($address['zip_code'] ?? ''),
            'city' => mb_substr((string) ($address['city'] ?? ''), 0, self::ADDRESS_LINE_LENGTH),
            'street' => mb_substr((string) ($address['street'] ?? ''), 0, self::ADDRESS_LINE_LENGTH),
            'phone' => $address['phone'] ?? null,
            'email' => $address['email'] ?? null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * A short, printable reason from the DPD answer: the remote text is reduced to letters, digits and simple
     * punctuation and cut, so a hostile or broken endpoint cannot echo arbitrary content through the API.
     */
    private function reasonOf(Response $response): string
    {
        $reason = $response->json('failed.0.reason') ?? $response->json('failed.0.errorCode') ?? $response->json('message');
        $reason = is_scalar($reason) ? trim((string) preg_replace('/[^\p{L}\p{N} .,:;_\-()\/]+/u', ' ', (string) $reason)) : '';

        return $reason === '' ? "HTTP {$response->status()}" : Str::limit((string) preg_replace('/\s+/', ' ', $reason), self::REASON_LENGTH, '...');
    }
}
