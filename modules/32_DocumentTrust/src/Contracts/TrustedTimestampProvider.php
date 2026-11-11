<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Contracts;

use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Support\TimestampToken;

/**
 * Binds a hash to a point in time. A qualified time stamp (eIDAS) comes from an external qualified trust service
 * provider, typically through RFC 3161; implementing that provider is an external integration that this application
 * does not fake. Register an implementation in the container (or name it in `services.preservation.timestamp_provider`
 * and bind it) and the closing of a package records its provider name and token.
 */
interface TrustedTimestampProvider
{
    /**
     * Name stored with the package.
     */
    public function name(): string;

    /**
     * Time-stamp the SHA-256 (hex) of a package manifest.
     *
     * @throws DocumentTrustException when the provider refuses or cannot be reached; the package then stays open
     */
    public function timestamp(string $sha256Hex): TimestampToken;
}
