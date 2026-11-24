<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Requests;

/**
 * A synchronisation of one resource of an installation. The resource must be one of the connector.
 */
final class SyncRequest extends MarketplaceFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['resource' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,59}$/']];
    }
}
