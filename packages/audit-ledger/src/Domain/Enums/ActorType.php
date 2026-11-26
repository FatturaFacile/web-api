<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Domain\Enums;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case ApiKey = 'api_key';
    case Job = 'job';

    /**
     * Entries written before the forensic extension used `system_job`.
     */
    public static function fromStored(?string $value): self
    {
        return match ($value) {
            'user' => self::User,
            'api_key' => self::ApiKey,
            'job', 'system_job' => self::Job,
            default => self::System,
        };
    }
}
