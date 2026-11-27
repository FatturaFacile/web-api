<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

/**
 * Reads the expiry date of the leaf certificate with a TLS handshake (peer verification stays on).
 */
final class StreamTlsCertificateInspector implements TlsCertificateInspector
{
    public function daysRemaining(string $host, int $port = 443, float $timeoutSeconds = 5.0): ?int
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client('ssl://'.$host.':'.$port, $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return null;
        }

        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null || ! function_exists('openssl_x509_parse')) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        return (int) floor(((int) $parsed['validTo_time_t'] - time()) / 86400);
    }
}
