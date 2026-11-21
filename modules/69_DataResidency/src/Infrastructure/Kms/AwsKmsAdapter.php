<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * AWS KMS (eu-central-2 Zurich, eu-south-1 Milan) over its JSON API with Signature V4. external_key_uri: the key ARN
 * (`arn:aws:kms:<region>:<account>:key/<id>`); credentials: `access_key_id`, `secret_access_key`, optional `session_token`.
 */
final class AwsKmsAdapter extends RestKmsAdapter
{
    public function type(): KmsProviderType
    {
        return KmsProviderType::AwsKms;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->call($envelope, 'Encrypt', ['KeyId' => $envelope->external_key_uri, 'Plaintext' => base64_encode($dek)]);

        return $this->stringAt($data, 'CiphertextBlob');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->call($envelope, 'Decrypt', ['KeyId' => $envelope->external_key_uri, 'CiphertextBlob' => $wrappedDek]);
        $plain = base64_decode($this->stringAt($data, 'Plaintext'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('AWS KMS returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        // The key id is stable; on-demand rotation adds a new backing key, tracked here by a monotonic label.
        $this->call($envelope, 'RotateKeyOnDemand', ['KeyId' => $envelope->external_key_uri]);

        return 'aws-'.now()->format('YmdHis');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(CustomerManagedKeyEnvelope $envelope, string $operation, array $body): array
    {
        $credentials = $this->credentials($envelope);
        $region = $this->regionOf($envelope->external_key_uri);
        $host = "kms.{$region}.amazonaws.com";
        $payload = (string) json_encode($body);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        $target = 'TrentService.'.$operation;
        $contentType = 'application/x-amz-json-1.1';

        $headers = ['content-type' => $contentType, 'host' => $host, 'x-amz-date' => $timestamp, 'x-amz-target' => $target];

        if (! empty($credentials['session_token'])) {
            $headers['x-amz-security-token'] = (string) $credentials['session_token'];
        }

        ksort($headers);
        $canonicalHeaders = '';

        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = implode("\n", ['POST', '/', '', $canonicalHeaders, $signedHeaders, hash('sha256', $payload)]);
        $scope = "{$date}/{$region}/kms/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $timestamp, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'kms', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.(string) ($credentials['secret_access_key'] ?? ''), true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $authorization = sprintf('AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', (string) ($credentials['access_key_id'] ?? ''), $scope, $signedHeaders, $signature);

        try {
            $response = Http::timeout(10)->withHeaders(array_merge($headers, ['Authorization' => $authorization]))->withBody($payload, $contentType)->post("https://{$host}/");
        } catch (ConnectionException) {
            throw new KmsUnavailableException('AWS KMS is unreachable.');
        }

        return $this->decode($response);
    }

    private function regionOf(string $arn): string
    {
        $parts = explode(':', $arn);

        if (count($parts) < 6 || $parts[0] !== 'arn' || $parts[2] !== 'kms' || $parts[3] === '') {
            throw new KmsUnavailableException('The AWS KMS key must be given as a key ARN.');
        }

        return $parts[3];
    }
}
