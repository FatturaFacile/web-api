<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\WebAuthn;

use Cose\Algorithms;
use Modules\Platform\Identity\Application\Passkeys\PasskeyAuthenticator;
use Modules\Platform\Identity\Application\Passkeys\PasskeyException;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkey cryptography on web-auth/webauthn-lib: "none" attestation (platform and roaming authenticators), ES256 and
 * RS256, user verification required, origins limited to config('passkeys.origins'). A signature counter that does not
 * grow (both zero is allowed: synced passkeys never count) means a cloned authenticator.
 */
final class WebAuthnLibAuthenticator implements PasskeyAuthenticator
{
    private SerializerInterface $serializer;

    private CeremonyStepManagerFactory $ceremonies;

    public function __construct()
    {
        $this->serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport]),
        ))->create();

        $this->ceremonies = new CeremonyStepManagerFactory;
        $this->ceremonies->setAllowedOrigins($this->origins());
        $this->ceremonies->setCounterChecker(new RejectRegressingCounter);
    }

    public function registrationOptions(string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create((string) config('passkeys.rp_name'), $this->rpId()),
            PublicKeyCredentialUserEntity::create($userName, $userHandle, $displayName),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256), PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256)],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            array_map(static fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, self::decode($id)), $excludeCredentialIds),
            (int) config('passkeys.timeout_ms'),
        );

        $json = $this->encode($options);

        return ['options' => (array) json_decode($json, true), 'state' => $json];
    }

    public function verifyRegistration(string $state, array $credential): array
    {
        try {
            $options = $this->serializer->deserialize($state, PublicKeyCredentialCreationOptions::class, 'json');
            $public = $this->serializer->deserialize((string) json_encode($credential), PublicKeyCredential::class, 'json');
            $response = $public->response;

            if (! $response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyException(PasskeyException::INVALID, 'Not an attestation response.');
            }

            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies->creationCeremony())
                ->check($response, $options, $this->rpId());
        } catch (PasskeyException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PasskeyException(PasskeyException::INVALID, $e->getMessage());
        }

        return [
            'credential_id' => self::encode64($record->publicKeyCredentialId),
            'record' => $this->serializer->serialize($record, 'json'),
            'sign_count' => $record->counter,
            'aaguid' => $record->aaguid->toRfc4122(),
        ];
    }

    public function loginOptions(): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            (int) config('passkeys.timeout_ms'),
        );

        $json = $this->encode($options);

        return ['options' => (array) json_decode($json, true), 'state' => $json];
    }

    public function peekAssertion(array $credential): array
    {
        $id = $credential['rawId'] ?? $credential['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new PasskeyException(PasskeyException::INVALID, 'Missing credential id.');
        }

        $handle = $credential['response']['userHandle'] ?? null;

        return [
            'credential_id' => rtrim(strtr($id, '+/', '-_'), '='),
            'user_handle' => is_string($handle) && $handle !== '' ? self::decode($handle) : null,
        ];
    }

    public function verifyAssertion(string $state, array $credential, string $record): array
    {
        try {
            $options = $this->serializer->deserialize($state, PublicKeyCredentialRequestOptions::class, 'json');
            $stored = $this->serializer->deserialize($record, CredentialRecord::class, 'json');
            $public = $this->serializer->deserialize((string) json_encode($credential), PublicKeyCredential::class, 'json');
            $response = $public->response;

            if (! $response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyException(PasskeyException::INVALID, 'Not an assertion response.');
            }

            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies->requestCeremony())
                ->check($stored, $response, $options, $this->rpId(), $response->userHandle);
        } catch (PasskeyException $e) {
            throw $e;
        } catch (CounterException $e) {
            throw new PasskeyException(PasskeyException::CLONED, $e->getMessage());
        } catch (Throwable $e) {
            if ($e->getPrevious() instanceof CounterException) {
                throw new PasskeyException(PasskeyException::CLONED, $e->getPrevious()->getMessage());
            }

            throw new PasskeyException(PasskeyException::INVALID, $e->getMessage());
        }

        return ['record' => $this->serializer->serialize($updated, 'json'), 'sign_count' => $updated->counter];
    }

    private function encode(object $options): string
    {
        return $this->serializer->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);
    }

    private function rpId(): string
    {
        return (string) config('passkeys.rp_id');
    }

    /**
     * @return list<string>
     */
    private function origins(): array
    {
        return array_values(array_map('strval', (array) config('passkeys.origins')));
    }

    private static function encode64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
    }
}
