<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum SyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
