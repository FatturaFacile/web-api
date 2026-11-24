<?php

declare(strict_types=1);

namespace Modules\SDI\Contracts;

use App\Models\Tenant;
use Modules\SDI\DTOs\SdiInboundItem;

/**
 * A channel that keeps unreadable or untrusted messages in quarantine and can read a stored message again.
 */
interface RereadsInboundMessages
{
    /**
     * Read a stored message again, as poll() would. Items carry no cursor: a retry never moves the channel cursor.
     *
     * @return list<SdiInboundItem>
     */
    public function reread(Tenant $tenant, string $raw, string $sourceReference): array;
}
