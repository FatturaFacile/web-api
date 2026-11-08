<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Modules\Communication\Exceptions\DeliveryFailedException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP connection of a company from its own settings. The mail server is a host the company typed in and the
 * application connects to it, so it goes through the outbound guard (server-side request forgery defence): only hosts
 * that resolve to public addresses are accepted. The connection then goes to the address that was checked, not to a
 * second DNS answer (DNS rebinding), while the certificate is still verified against the real host name. With
 * `encryption` `tls` (the default) the connection must upgrade to TLS before any password is sent.
 */
class SmtpTransportFactory
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * @param  array<string, string>  $credentials  host, port, encryption (tls, ssl or none), username, password
     *
     * @throws DeliveryFailedException when the host is missing or not a public one
     */
    public function make(array $credentials): TransportInterface
    {
        $host = trim((string) ($credentials['host'] ?? ''));
        $port = (int) ($credentials['port'] ?? 587);
        $encryption = (string) ($credentials['encryption'] ?? 'tls');

        if ($host === '') {
            throw new DeliveryFailedException('The SMTP server is not configured.');
        }

        try {
            $target = $this->guard->inspectHost($host, $port);
        } catch (UnsafeOutboundUrlException) {
            throw new DeliveryFailedException('The SMTP server must be a public host.');
        }

        $address = $target->addresses[0] ?? $target->host;
        $transport = new EsmtpTransport($address, $port, $encryption === 'ssl');
        $transport->setRequireTls($encryption === 'tls');

        if (($credentials['username'] ?? '') !== '') {
            $transport->setUsername((string) $credentials['username']);
            $transport->setPassword((string) ($credentials['password'] ?? ''));
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setStreamOptions(['ssl' => ['peer_name' => $target->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        }

        return $transport;
    }
}
