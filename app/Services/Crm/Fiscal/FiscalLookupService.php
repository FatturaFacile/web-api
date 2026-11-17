<?php

declare(strict_types=1);

namespace App\Services\Crm\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Registry lookup used to autofill a customer from an Italian Partita IVA (VIES) or a Swiss UID (Zefix).
 * The identifier is always validated formally first; a registry outage never blocks the user, it only lowers
 * the result to `checksum` certification.
 */
class FiscalLookupService
{
    /**
     * @return array{
     *     valid_format: bool,
     *     registry_verified: bool,
     *     source: string,
     *     data: array<string, mixed>,
     *     warnings: array<int, string>
     * }
     */
    public function lookup(string $country, string $identifier): array
    {
        return match (strtoupper($country)) {
            'IT' => $this->lookupItalianVat($identifier),
            'CH' => $this->lookupSwissUid($identifier),
            default => throw new InvalidArgumentException("Registry lookup is not available for country {$country}."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupItalianVat(string $identifier): array
    {
        $vat = FiscalIdentifiers::normalizeItalianVat($identifier);

        if (! FiscalIdentifiers::isValidItalianVat($vat)) {
            return $this->result(false, false, 'checksum', [], ['The Partita IVA is not formally valid.']);
        }

        $data = ['country' => 'IT', 'vat_number' => $vat];

        try {
            $response = Http::timeout((int) config('services.vies.timeout', 8))
                ->acceptJson()
                ->get(rtrim((string) config('services.vies.base_url'), '/')."/ms/IT/vat/{$vat}");

            if ($response->successful()) {
                $body = $response->json();

                if (($body['isValid'] ?? $body['valid'] ?? false) === true) {
                    return $this->result(true, true, 'vies', array_filter([
                        ...$data,
                        'company_name' => $this->clean($body['name'] ?? null),
                        'address' => $this->parseItalianAddress($this->clean($body['address'] ?? null)),
                    ], fn (mixed $v): bool => $v !== null), []);
                }

                return $this->result(true, false, 'vies', $data, ['The Partita IVA is not active in VIES.']);
            }

            $warnings = ['VIES is temporarily unavailable: only the formal check was performed.'];
        } catch (ConnectionException $e) {
            Log::warning('VIES lookup failed', ['error' => $e->getMessage()]);
            $warnings = ['VIES is unreachable: only the formal check was performed.'];
        }

        return $this->result(true, false, 'checksum', $data, $warnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupSwissUid(string $identifier): array
    {
        $uid = FiscalIdentifiers::normalizeSwissUid($identifier);

        if ($uid === null || ! FiscalIdentifiers::isValidSwissUid($uid)) {
            return $this->result(false, false, 'checksum', [], ['The UID is not formally valid (expected CHE-xxx.xxx.xxx).']);
        }

        $data = ['country' => 'CH', 'uid_number' => $uid];

        if (empty(config('services.zefix.username'))) {
            return $this->result(true, false, 'checksum', $data, ['Zefix credentials are not configured: only the UID checksum was verified.']);
        }

        try {
            $response = $this->zefix()->post(rtrim((string) config('services.zefix.base_url'), '/').'/firm/search.json', [
                'uid' => $uid,
                'languageKey' => 'it',
                'maxEntries' => 1,
                'offset' => 0,
            ]);

            if ($response->successful()) {
                $body = $response->json();
                $firm = $body['list'][0] ?? $body[0] ?? null;

                if (is_array($firm)) {
                    return $this->result(true, true, 'zefix', [
                        ...$data,
                        'company_name' => $this->clean($firm['name'] ?? null),
                        'legal_form' => $firm['legalForm']['name']['it'] ?? $firm['legalForm']['shortName']['it'] ?? null,
                        'status' => $firm['status'] ?? null,
                        'address' => array_filter(['city' => $this->clean($firm['legalSeat'] ?? null), 'country' => 'CH']),
                    ], []);
                }

                return $this->result(true, false, 'zefix', $data, ['The UID was not found in the Zefix commercial register.']);
            }

            $warnings = ['Zefix is temporarily unavailable: only the UID checksum was verified.'];
        } catch (ConnectionException $e) {
            Log::warning('Zefix lookup failed', ['error' => $e->getMessage()]);
            $warnings = ['Zefix is unreachable: only the UID checksum was verified.'];
        }

        return $this->result(true, false, 'checksum', $data, $warnings);
    }

    private function zefix(): PendingRequest
    {
        return Http::timeout((int) config('services.zefix.timeout', 8))
            ->withBasicAuth((string) config('services.zefix.username'), (string) config('services.zefix.password'))
            ->acceptJson()
            ->asJson();
    }

    /**
     * VIES returns "VIA ROMA 1\n20100 MILANO MI": split it into street, postal code, city and province.
     *
     * @return array<string, string>|null
     */
    private function parseItalianAddress(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));

        if ($lines === []) {
            return null;
        }

        $address = ['country' => 'IT'];
        $last = $lines[count($lines) - 1];

        if (count($lines) > 1 && preg_match('/^(\d{5})\s+(.+?)(?:\s+([A-Z]{2}))?$/u', $last, $m)) {
            $address['postal_code'] = $m[1];
            $address['city'] = $m[2];

            if (isset($m[3])) {
                $address['state_canton'] = $m[3];
            }

            array_pop($lines);
        }

        $address['street'] = implode(' ', $lines);

        return $address;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || $value === '---' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function result(bool $validFormat, bool $verified, string $source, array $data, array $warnings): array
    {
        return [
            'valid_format' => $validFormat,
            'registry_verified' => $verified,
            'source' => $source,
            'data' => $data,
            'warnings' => $warnings,
        ];
    }
}
