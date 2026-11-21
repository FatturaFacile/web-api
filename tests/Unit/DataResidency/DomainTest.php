<?php

declare(strict_types=1);

use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\KeyEnvelopeStatus;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Enums\TransferDecision;
use Modules\DataResidency\Domain\Exceptions\CrossBorderTransferBlockedException;
use Modules\DataResidency\Domain\Exceptions\TenantCryptoShreddedOrSuspendedException;
use Modules\DataResidency\Domain\ValueObjects\AdequacyRegistry;
use Modules\DataResidency\Domain\ValueObjects\GeoNodeCoordinates;
use Modules\DataResidency\Infrastructure\Crypto\EncryptionContext;
use Modules\DataResidency\Infrastructure\Kms\KmsRegionPolicy;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\EgressCountryResolver;

it('limits each zone to its countries', function (): void {
    expect(JurisdictionZone::StrictChOnly->allowedCountries())->toBe(['CH'])
        ->and(JurisdictionZone::StrictItOnly->allowsCountry('it'))->toBeTrue()
        ->and(JurisdictionZone::StrictItOnly->allowsCountry('CH'))->toBeFalse()
        ->and(JurisdictionZone::EeaPlusAdequacyCh->allowsCountry('CH'))->toBeTrue()
        ->and(JurisdictionZone::EeaPlusAdequacyCh->allowsCountry('US'))->toBeFalse()
        ->and(JurisdictionZone::StrictChOnly->complianceFrameworks())->toContain('FINMA Circ. 2018/3', 'Art. 321 CP')
        ->and(JurisdictionZone::StrictItOnly->complianceFrameworks())->toContain('GDPR Art. 44-50', 'ACN/AgID');
});

it('knows the adequacy lists of Switzerland and the EU', function (): void {
    expect(AdequacyRegistry::hasChAdequacy('DE'))->toBeTrue()->and(AdequacyRegistry::hasChAdequacy('us'))->toBeFalse()
        ->and(AdequacyRegistry::hasEuAdequacy('CH'))->toBeTrue()->and(AdequacyRegistry::hasEuAdequacy('RU'))->toBeFalse()
        ->and(AdequacyRegistry::decisionFor(JurisdictionZone::StrictChOnly, 'GB'))->toBe(TransferDecision::Allowed)
        ->and(AdequacyRegistry::decisionFor(JurisdictionZone::StrictItOnly, 'IN'))->toBe(TransferDecision::Blocked);
});

it('blocks an outbound endpoint from the policy itself', function (): void {
    $policy = new TenantDataResidencyPolicy;
    $policy->tenant_id = 'tenant';
    $policy->jurisdiction_zone = JurisdictionZone::StrictChOnly;
    $policy->enforce_strict_egress_block = true;

    $policy->validateOutboundEndpoint('FR', 'webhook');
    expect(fn () => $policy->validateOutboundEndpoint('US', 'webhook'))->toThrow(CrossBorderTransferBlockedException::class)
        ->and(fn () => $policy->validateOutboundEndpoint('FR', 'webhook', TransferDecision::Blocked))->toThrow(CrossBorderTransferBlockedException::class);
});

it('keeps the key envelope lifecycle invariants', function (): void {
    $envelope = new CustomerManagedKeyEnvelope;
    $envelope->tenant_id = 'tenant';
    $envelope->status = KeyEnvelopeStatus::Active;
    $envelope->key_management_mode = KeyManagementMode::Hyok;
    $envelope->current_key_version = 'v1';
    $envelope->rotation_interval_days = 30;
    $envelope->dek_cache_ttl_seconds = 600;

    $envelope->ensureUsable();
    $envelope->rotateKey('v2');
    expect($envelope->current_key_version)->toBe('v2')->and($envelope->status)->toBe(KeyEnvelopeStatus::Active)
        ->and($envelope->next_rotation_due_at->isFuture())->toBeTrue();

    $envelope->triggerKillSwitch('actor', 'incident');
    expect($envelope->status)->toBe(KeyEnvelopeStatus::Suspended)->and($envelope->dek_cache_ttl_seconds)->toBe(0)
        ->and(fn () => $envelope->ensureUsable())->toThrow(TenantCryptoShreddedOrSuspendedException::class)
        ->and(fn () => $envelope->rotateKey('v3'))->toThrow(TenantCryptoShreddedOrSuspendedException::class);

    $envelope->triggerKillSwitch('actor', 'incident', true);
    expect($envelope->status)->toBe(KeyEnvelopeStatus::RevokedCryptoShredded)->and((new TenantCryptoShreddedOrSuspendedException)->httpStatus())->toBe(423);
});

it('describes a node location with its certifications', function (): void {
    $node = new GeoNodeCoordinates('ch-zrh-01', 'eu-central-2', 'CH', 'ZH', 'Zurich', 'Provider', ['ISO-27001'], 47.37, 8.54);

    expect($node->toArray())->toMatchArray(['node_code' => 'ch-zrh-01', 'canton_or_region' => 'ZH', 'certifications' => ['ISO-27001'], 'latitude' => 47.37]);
});

it('binds the additional authenticated data to every part of the context', function (): void {
    $base = new EncryptionContext('t', 'e', 'invoices', 'r');

    expect($base->aad())->toBe((new EncryptionContext('t', 'e', 'invoices', 'r'))->aad())
        ->and($base->aad())->not->toBe((new EncryptionContext('t2', 'e', 'invoices', 'r'))->aad())
        ->and($base->aad())->not->toBe((new EncryptionContext('t', null, 'invoices', 'r'))->aad())
        ->and($base->aad())->not->toBe((new EncryptionContext('t', 'e', 'payslips', 'r'))->aad())
        ->and($base->aad())->not->toBe((new EncryptionContext('t', 'e', 'invoices', 'r2'))->aad());
});

it('resolves the country of a destination', function (): void {
    config(['data_residency.host_countries' => ['partner.example' => 'CH']]);
    $resolver = new EgressCountryResolver;

    expect($resolver->resolve('https://api.partner.example/x'))->toBe('CH')
        ->and($resolver->resolve('https://shop.example.co.uk/x'))->toBe('GB')
        ->and($resolver->resolve('https://shop.example.de'))->toBe('DE')
        ->and($resolver->resolve('https://api.cloud.com'))->toBeNull()
        ->and($resolver->resolve('https://api.cloud.com', 'it'))->toBe('IT');
});

it('locates a self-hosted KMS only when the customer declares its country', function (): void {
    $policy = new KmsRegionPolicy;

    expect($policy->countryOf(KmsProviderType::AwsKms, 'arn:aws:kms:eu-south-1:1:key/a', []))->toBe('IT')
        ->and($policy->countryOf(KmsProviderType::GoogleCloudEkm, 'projects/p/locations/europe-west6/keyRings/r/cryptoKeys/k', []))->toBe('CH')
        ->and($policy->countryOf(KmsProviderType::HashicorpVault, 'https://v', ['country_iso2' => 'ch']))->toBe('CH')
        ->and($policy->countryOf(KmsProviderType::HashicorpVault, 'https://v', []))->toBeNull()
        ->and($policy->countryOf(KmsProviderType::AwsKms, 'arn:aws:kms:xx-unknown-1:1:key/a', []))->toBeNull();
});
