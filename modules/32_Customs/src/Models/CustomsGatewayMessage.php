<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

/**
 * A message exchanged with a customs channel. The content stays on the private disk.
 */
final class CustomsGatewayMessage extends CustomsRecord
{
    protected $hidden = ['storage_path'];
}
