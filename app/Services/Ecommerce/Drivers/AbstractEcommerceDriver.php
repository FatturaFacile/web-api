<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Drivers;

use App\Contracts\Ecommerce\EcommerceDriverInterface;
use App\Exceptions\EcommerceException;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Product;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Shared plumbing of the platform drivers: credentials, HTTP error handling, mapping lookup and signature helpers.
 * Drivers talk to the shop exclusively through Laravel's HTTP client, so tests can fake every call.
 */
abstract class AbstractEcommerceDriver implements EcommerceDriverInterface
{
    protected const TIMEOUT_SECONDS = 20;

    protected const PAGE_SIZE = 100;

    protected const MAX_PAGES = 20;

    public function __construct(protected readonly EcommerceChannel $channel) {}

    /**
     * Root URL of the platform API, without a trailing slash.
     */
    abstract protected function baseUrl(): string;

    /**
     * An HTTP client with the platform authentication applied.
     */
    abstract protected function http(): PendingRequest;

    /**
     * Convert a platform order into the normalised order structure documented on the driver interface.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    abstract protected function normalizeOrder(array $order): array;

    protected function credential(string $key): string
    {
        $value = $this->channel->credentials[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw EcommerceException::because(EcommerceException::MISSING_CONFIGURATION, ['field' => "credentials.{$key}"]);
        }

        return $value;
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->channel->settings[$key] ?? $default;
    }

    protected function apiUrl(): string
    {
        $url = (string) $this->channel->api_url;

        if ($url === '') {
            throw EcommerceException::because(EcommerceException::MISSING_CONFIGURATION, ['field' => 'api_url']);
        }

        return rtrim($url, '/');
    }

    /**
     * The mapping of a product (or of a variant) on this channel, if it was synchronised before.
     */
    protected function mappingFor(Product $product): ?EcommerceMapping
    {
        return EcommerceMapping::withoutGlobalScopes()
            ->where('tenant_id', $this->channel->tenant_id)
            ->where('channel_id', $this->channel->id)
            ->where('entity_type', $product->is_variant ? 'product_variant' : 'product')
            ->where('local_id', $product->id)
            ->first();
    }

    protected function ensureSuccessful(Response $response, string $operation): Response
    {
        if ($response->failed()) {
            throw EcommerceException::because(EcommerceException::HTTP_ERROR, ['status' => $response->status(), 'operation' => $operation]);
        }

        return $response;
    }

    /**
     * The decoded JSON body, which must be an array.
     *
     * @return array<mixed>
     */
    protected function jsonBody(Response $response, string $operation): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw EcommerceException::because(EcommerceException::INVALID_RESPONSE, ['operation' => $operation]);
        }

        return $data;
    }

    protected function stockQuantity(float $quantity): int
    {
        return max(0, (int) round($quantity));
    }

    /**
     * HMAC-SHA256 of the raw webhook body with the channel secret.
     */
    protected function bodyHmac(Request $request, bool $base64): string
    {
        $secret = (string) $this->channel->webhook_secret;

        return $secret === '' ? '' : ($base64
            ? base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true))
            : hash_hmac('sha256', $request->getContent(), $secret));
    }

    protected function signatureMatches(string $expected, ?string $given): bool
    {
        return $expected !== '' && is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Net unit price and tax rate (percent) from gross and net amounts of a line.
     */
    protected function taxRate(float $net, float $tax): float
    {
        return $net > 0 ? round($tax / $net * 100, 2) : 0.0;
    }
}
