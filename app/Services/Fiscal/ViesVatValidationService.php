<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\VatValidationServiceInterface;
use App\DTOs\Fiscal\VatValidationResult;
use App\Exceptions\Fiscal\InvalidVatFormatException;
use App\Support\Fiscal\EuVatFormat;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates EU VAT numbers against the VIES REST service of the European Commission
 * (https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number).
 *
 * Only definitive answers (valid / invalid) are cached; every outage becomes an `unavailable` result so that CRM saving
 * and invoicing are never blocked while VIES (or a Member State database) is down.
 */
final class ViesVatValidationService implements VatValidationServiceInterface
{
    /** VIES `userError` values that mean "the service could not answer", as opposed to a verdict. */
    private const array UNAVAILABLE_ERRORS = ['MS_UNAVAILABLE', 'SERVICE_UNAVAILABLE', 'TIMEOUT', 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ_TIME', 'GLOBAL_MAX_CONCURRENT_REQ_TIME'];

    public function validate(string $vatNumber, ?string $countryCode = null): VatValidationResult
    {
        $parts = EuVatFormat::split($vatNumber, $countryCode);

        if ($parts === null || ! EuVatFormat::isValid($parts[0], $parts[1])) {
            throw new InvalidVatFormatException('The VAT number is not formally valid for an EU Member State.');
        }

        [$country, $number] = $parts;
        $cacheKey = "vies:{$country}{$number}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $this->fromCached($cached)->withFromCache();
        }

        $result = $this->query($country, $number);

        if ($result->status !== VatValidationResult::STATUS_UNAVAILABLE) {
            Cache::put($cacheKey, $result->toArray(), now()->addSeconds((int) config('services.vies.cache_ttl', 86400)));
        }

        return $result;
    }

    private function query(string $country, string $number): VatValidationResult
    {
        $now = new DateTimeImmutable;
        $payload = ['countryCode' => $country, 'vatNumber' => $number];

        // With the requester's own VAT number VIES returns the consultation identifier, the audit proof of the check.
        $requesterCountry = config('services.vies.requester_country');
        $requesterNumber = config('services.vies.requester_number');
        if (is_string($requesterCountry) && is_string($requesterNumber) && $requesterNumber !== '') {
            $payload['requesterMemberStateCode'] = $requesterCountry;
            $payload['requesterNumber'] = $requesterNumber;
        }

        try {
            $response = Http::timeout((int) config('services.vies.timeout', 8))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('services.vies.base_url'), '/').'/check-vat-number', $payload);
        } catch (ConnectionException $e) {
            Log::warning('VIES unreachable', ['error' => $e->getMessage()]);

            return $this->unavailable($country, $number, $now, 'TIMEOUT');
        } catch (Throwable $e) {
            Log::warning('VIES call failed', ['error' => $e->getMessage()]);

            return $this->unavailable($country, $number, $now, 'SERVICE_UNAVAILABLE');
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $userError = isset($body['userError']) && is_string($body['userError']) ? strtoupper($body['userError']) : null;
        $errorWrappers = $body['errorWrappers'] ?? null;
        if ($userError === null && is_array($errorWrappers) && isset($errorWrappers[0]['error']) && is_string($errorWrappers[0]['error'])) {
            $userError = strtoupper($errorWrappers[0]['error']);
        }

        if ($userError !== null && in_array($userError, self::UNAVAILABLE_ERRORS, true)) {
            return $this->unavailable($country, $number, $now, $userError);
        }

        if (! $response->successful() && ! array_key_exists('valid', $body)) {
            return $this->unavailable($country, $number, $now, $response->status() === 408 ? 'TIMEOUT' : 'SERVICE_UNAVAILABLE');
        }

        $valid = ($body['valid'] ?? $body['isValid'] ?? false) === true;

        return new VatValidationResult(
            status: $valid ? VatValidationResult::STATUS_VALID : VatValidationResult::STATUS_INVALID,
            isValid: $valid,
            countryCode: $country,
            vatNumber: $number,
            traderName: $valid ? $this->clean($body['name'] ?? null) : null,
            traderAddress: $valid ? $this->clean($body['address'] ?? null) : null,
            requestIdentifier: $this->clean($body['requestIdentifier'] ?? null),
            validatedAt: $now,
            errorCode: $valid ? null : $userError,
        );
    }

    private function unavailable(string $country, string $number, DateTimeImmutable $now, string $errorCode): VatValidationResult
    {
        return new VatValidationResult(VatValidationResult::STATUS_UNAVAILABLE, null, $country, $number, null, null, null, $now, $errorCode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fromCached(array $data): VatValidationResult
    {
        return new VatValidationResult(
            status: (string) $data['status'],
            isValid: $data['is_valid'],
            countryCode: (string) $data['country_code'],
            vatNumber: (string) $data['vat_number'],
            traderName: $data['trader_name'],
            traderAddress: $data['trader_address'],
            requestIdentifier: $data['request_identifier'],
            validatedAt: new DateTimeImmutable((string) $data['validated_at']),
            errorCode: $data['error_code'],
        );
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/[ \t]+/', ' ', $value));

        return $value === '' || $value === '---' ? null : $value;
    }
}
