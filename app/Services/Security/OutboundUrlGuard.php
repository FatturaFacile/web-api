<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\HostResolver;
use App\Exceptions\UnsafeOutboundUrlException;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The single gate for requests the server makes on behalf of a tenant (server-side request forgery defence).
 *
 * A host is accepted only when every address it resolves to is public: loopback, private, link-local (cloud metadata
 * included), carrier-grade NAT, multicast, documentation, benchmarking and reserved IPv4 ranges are refused, and so
 * are IPv6 loopback, unique-local, link-local, site-local, multicast, IPv4-mapped, NAT64, 6to4 and Teredo addresses.
 * Non-canonical IPv4 spellings (`2130706433`, `0x7f.1`, `127.1`, `0177.0.0.1`) are refused outright.
 *
 * {@see protect()} applies the check to every request of an HTTP client, pins the checked addresses with
 * `CURLOPT_RESOLVE` (no second DNS lookup, so no DNS rebinding) and disables redirects.
 */
final class OutboundUrlGuard
{
    /**
     * IPv4 ranges that are not publicly routable (RFC 6890 and friends).
     *
     * @var list<string>
     */
    private const array BLOCKED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /**
     * IPv6 ranges refused even inside global unicast space (2000::/3), which is the only space accepted at all.
     *
     * @var list<string>
     */
    private const array BLOCKED_IPV6 = [
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
    ];

    /**
     * Names that always designate the local machine or an internal network.
     *
     * @var list<string>
     */
    private const array BLOCKED_NAMES = ['localhost', 'localhost.localdomain', 'metadata', 'ip6-localhost', 'ip6-loopback'];

    /**
     * @var list<string>
     */
    private const array BLOCKED_SUFFIXES = ['.localhost', '.local', '.localdomain', '.internal', '.intranet', '.lan', '.home.arpa', '.corp'];

    /**
     * @param  list<string>  $allowedHosts  host names or `*.suffix` patterns that skip the check (local development only)
     */
    public function __construct(
        private readonly HostResolver $resolver,
        private readonly array $allowedHosts = [],
    ) {}

    /**
     * Check an https URL (plain http is never accepted by default) and resolve its host.
     *
     * @param  list<string>  $schemes
     *
     * @throws UnsafeOutboundUrlException
     */
    public function inspectUrl(string $url, array $schemes = ['https']): OutboundTarget
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_URL);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($parts === false || ! in_array($scheme, $schemes, true) || ! isset($parts['host']) || $parts['host'] === '') {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_URL);
        }

        return $this->inspectHost($parts['host'], $parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * Check a bare host (IMAP, SMTP) and resolve it.
     *
     * @throws UnsafeOutboundUrlException
     */
    public function inspectHost(string $host, int $port): OutboundTarget
    {
        $host = $this->normalizeHost($host);

        if ($port < 1 || $port > 65535) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_URL, $host);
        }

        if ($this->isAllowListed($host)) {
            return new OutboundTarget($host, $port, [], allowListed: true);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicAddress($host)) {
                throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS, $host);
            }

            return new OutboundTarget($host, $port, [$host]);
        }

        if (in_array($host, self::BLOCKED_NAMES, true) || array_any(self::BLOCKED_SUFFIXES, fn (string $suffix): bool => str_ends_with($host, $suffix))) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS, $host);
        }

        try {
            $addresses = $this->resolver->resolve($host);
        } catch (Throwable) {
            $addresses = [];
        }

        if ($addresses === []) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::UNRESOLVABLE_HOST, $host);
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || ! $this->isPublicAddress($address)) {
                throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::NON_PUBLIC_ADDRESS, $host);
            }
        }

        return new OutboundTarget($host, $port, array_values(array_unique($addresses)));
    }

    public function isPublicUrl(string $url): bool
    {
        try {
            $this->inspectUrl($url);

            return true;
        } catch (UnsafeOutboundUrlException) {
            return false;
        }
    }

    public function isPublicHost(string $host, int $port = 443): bool
    {
        try {
            $this->inspectHost($host, $port);

            return true;
        } catch (UnsafeOutboundUrlException) {
            return false;
        }
    }

    /**
     * Whether a literal IP address is publicly routable.
     */
    public function isPublicAddress(string $address): bool
    {
        $address = trim($address, '[]');

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! array_any(self::BLOCKED_IPV4, fn (string $range): bool => $this->inRange($address, $range));
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        // Global unicast only: this excludes ::, ::1, IPv4-mapped/compatible, NAT64 (64:ff9b::/96), discard (100::/64),
        // unique-local (fc00::/7), link-local (fe80::/10), site-local (fec0::/10) and multicast (ff00::/8).
        return $this->inRange($address, '2000::/3')
            && ! array_any(self::BLOCKED_IPV6, fn (string $range): bool => $this->inRange($address, $range));
    }

    /**
     * The client with the check on every request it sends, the checked addresses pinned and redirects disabled.
     *
     * @param  (Closure(UnsafeOutboundUrlException): Throwable)|null  $mapException  turns a refusal into the caller's exception
     */
    public function protect(PendingRequest $request, ?Closure $mapException = null): PendingRequest
    {
        return $request->withoutRedirecting()->withMiddleware($this->middleware($mapException));
    }

    /**
     * Guzzle middleware: checks the URI of each request and pins the resolved addresses for curl.
     *
     * @param  (Closure(UnsafeOutboundUrlException): Throwable)|null  $mapException
     * @return Closure(callable): Closure
     */
    public function middleware(?Closure $mapException = null): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler, $mapException) {
            try {
                $target = $this->inspectUrl((string) $request->getUri());
            } catch (UnsafeOutboundUrlException $exception) {
                throw $mapException === null ? $exception : $mapException($exception);
            }

            $pin = $target->curlResolveEntry();

            if ($pin !== null && defined('CURLOPT_RESOLVE')) {
                $options['curl'] = ($options['curl'] ?? []);
                $options['curl'][CURLOPT_RESOLVE] = [$pin];
            }

            $options['allow_redirects'] = false;

            return $handler($request, $options);
        };
    }

    /**
     * Lower-case host without brackets or trailing dot, converted to ASCII; non-canonical numeric IPv4 is refused.
     *
     * @throws UnsafeOutboundUrlException
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $literal = substr($host, 1, -1);

            if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_HOST, $host);
            }

            return $literal;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $host;
        }

        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;

        if (preg_match('/[^\x21-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $host = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        if ($host === '' || strlen($host) > 253
            || preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $host) !== 1) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_HOST, $host);
        }

        // A name whose last label is a number is an IPv4 address in one of the inet_aton() spellings that curl and the
        // system resolver accept (decimal, octal, hex, fewer than four parts): only the canonical dotted quad passes.
        $lastLabel = substr((string) strrchr('.'.$host, '.'), 1);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $lastLabel) === 1 && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new UnsafeOutboundUrlException(UnsafeOutboundUrlException::INVALID_HOST, $host);
        }

        return $host;
    }

    private function isAllowListed(string $host): bool
    {
        foreach ($this->allowedHosts as $pattern) {
            $pattern = strtolower(trim($pattern));

            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $binary = @inet_pton($address);
        $networkBinary = @inet_pton($network);

        if ($binary === false || $networkBinary === false || strlen($binary) !== strlen($networkBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($binary, $networkBinary, $bytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }
}
