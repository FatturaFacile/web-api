<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

/**
 * What the customs domain needs to know about a company. No secret is kept here: the client certificate of a real
 * channel is configured on the server.
 */
final class CustomsSettings extends CustomsRecord
{
    protected $table = 'customs_settings';

    protected function casts(): array
    {
        return ['auto_retrieve' => 'boolean'];
    }
}
