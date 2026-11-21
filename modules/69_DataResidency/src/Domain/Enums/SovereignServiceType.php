<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum SovereignServiceType: string
{
    case PostgresPrimary = 'postgres_primary';
    case PostgresReplica = 'postgres_replica';
    case S3Dms = 's3_dms';
    case Pgvector = 'pgvector';
    case AiInference = 'ai_inference';
    case BackupVault = 'backup_vault';
    case QueueBroker = 'queue_broker';
}
