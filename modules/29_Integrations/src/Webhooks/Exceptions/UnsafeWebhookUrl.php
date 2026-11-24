<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks\Exceptions;

use RuntimeException;

/**
 * The URL of an endpoint is not one a webhook may call (not https, credentials in it, or an address that is not on the
 * public internet).
 */
final class UnsafeWebhookUrl extends RuntimeException {}
