<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;

/**
 * A key held outside the jurisdiction defeats the residency of the data it protects: the country of the external KMS
 * (from the key ARN or resource name, or declared by the customer for a self-hosted HSM) must be inside the zone.
 */
final class KmsRegionPolicy
{
    /** Cloud region => ISO country. */
    private const array REGIONS = [
        'eu-central-2' => 'CH', 'eu-south-1' => 'IT', 'eu-central-1' => 'DE', 'eu-west-1' => 'IE', 'eu-west-2' => 'GB', 'eu-west-3' => 'FR',
        'eu-north-1' => 'SE', 'us-east-1' => 'US', 'us-east-2' => 'US', 'us-west-1' => 'US', 'us-west-2' => 'US',
        'switzerlandnorth' => 'CH', 'switzerlandwest' => 'CH', 'italynorth' => 'IT', 'germanywestcentral' => 'DE', 'westeurope' => 'NL',
        'northeurope' => 'IE', 'francecentral' => 'FR', 'eastus' => 'US', 'westus' => 'US',
        'europe-west6' => 'CH', 'europe-west8' => 'IT', 'europe-west3' => 'DE', 'europe-west1' => 'BE', 'europe-west4' => 'NL', 'us-central1' => 'US',
    ];

    /**
     * @param  array<string, mixed>  $credentials  The decrypted credentials the customer supplied
     */
    public function countryOf(KmsProviderType $provider, string $keyUri, array $credentials): ?string
    {
        $declared = $credentials['country_iso2'] ?? null;

        $region = match ($provider) {
            KmsProviderType::AwsKms => explode(':', $keyUri)[3] ?? null,
            KmsProviderType::GoogleCloudEkm => preg_match('#/locations/([a-z0-9-]+)/#', $keyUri, $m) === 1 ? $m[1] : null,
            KmsProviderType::AzureKeyVault => is_string($credentials['region'] ?? null) ? strtolower($credentials['region']) : null,
            default => null,
        };

        if (is_string($region) && isset(self::REGIONS[$region])) {
            return self::REGIONS[$region];
        }

        return in_array($provider, [KmsProviderType::SecurosysPrimusHsm, KmsProviderType::HashicorpVault, KmsProviderType::KmipGeneric], true) && is_string($declared)
            ? strtoupper($declared)
            : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     *
     * @throws CrossBorderResidencyViolationException
     */
    public function assertAllowed(JurisdictionZone $zone, KeyManagementMode $mode, KmsProviderType $provider, string $keyUri, array $credentials): void
    {
        if (! $mode->isCustomerManaged()) {
            return;
        }

        $country = $this->countryOf($provider, $keyUri, $credentials);

        if ($country === null || ! $zone->allowsCountry($country)) {
            throw new CrossBorderResidencyViolationException(sprintf(
                'The %s key is %s: a %s policy requires a KMS/HSM located in %s.',
                $provider->value,
                $country === null ? 'in an unverifiable location' : 'located in '.$country,
                $zone->value,
                $zone === JurisdictionZone::EeaPlusAdequacyCh ? 'the EEA or Switzerland' : $zone->allowedCountries()[0],
            ));
        }
    }
}
