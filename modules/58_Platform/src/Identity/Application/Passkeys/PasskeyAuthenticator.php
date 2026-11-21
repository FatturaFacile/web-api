<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application\Passkeys;

/**
 * Port of the WebAuthn cryptography. The implementation builds options and verifies attestations and assertions; it
 * knows nothing about users, tenants or storage, which stay in PasskeyService.
 */
interface PasskeyAuthenticator
{
    /**
     * @param  list<string>  $excludeCredentialIds  base64url ids the user already registered
     * @return array{options: array<string, mixed>, state: string}
     */
    public function registrationOptions(string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): array;

    /**
     * @param  array<string, mixed>  $credential  PublicKeyCredential JSON as produced by navigator.credentials.create()
     * @return array{credential_id: string, record: string, sign_count: int, aaguid: string}
     *
     * @throws PasskeyException
     */
    public function verifyRegistration(string $state, array $credential): array;

    /**
     * @return array{options: array<string, mixed>, state: string}
     */
    public function loginOptions(): array;

    /**
     * Reads the credential id and user handle of an assertion, to find the stored record before verification.
     *
     * @param  array<string, mixed>  $credential
     * @return array{credential_id: string, user_handle: string|null}
     *
     * @throws PasskeyException
     */
    public function peekAssertion(array $credential): array;

    /**
     * @param  array<string, mixed>  $credential  PublicKeyCredential JSON as produced by navigator.credentials.get()
     * @param  string  $record  stored credential record
     * @return array{record: string, sign_count: int}
     *
     * @throws PasskeyException
     */
    public function verifyAssertion(string $state, array $credential, string $record): array;
}
