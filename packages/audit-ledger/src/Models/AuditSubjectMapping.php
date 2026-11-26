<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * token => data subject. The display name is encrypted and the row is the only place where a pseudonym can be resolved:
 * deleting it (EraseAuditSubjectCommand) erases the person from the ledger without touching a hash. A mapping is never
 * edited.
 *
 * @property string $token
 * @property string $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $display_name encrypted
 */
final class AuditSubjectMapping extends Model
{
    protected $table = 'audit_subject_map';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $guarded = [];

    protected $hidden = ['display_name'];

    protected static function boot(): void
    {
        parent::boot();

        self::updating(static function (): void {
            throw new LogicException('An audit subject mapping is never edited: it is created once and erased as a whole.');
        });
    }
}
