<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;

/**
 * A version of a processing activity of the register. A draft is edited; once final it is frozen, and a change means a
 * new version of the same family, which supersedes the previous one when finalized.
 *
 * @property string $id
 * @property string $family_id
 * @property int $version
 * @property string $status
 * @property list<string> $legal_bases
 */
final class ProcessingActivity extends AssociationRecord
{
    public const string Draft = 'draft';

    public const string Final = 'final';

    public const string Superseded = 'superseded';

    /**
     * The lawful bases of Article 6(1) of Regulation (EU) 2016/679, points (a) to (f).
     */
    public const array LegalBases = ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'];

    protected $table = 'assoc_processing_activities';

    protected static function booted(): void
    {
        self::updating(static function (self $activity): void {
            $original = $activity->getOriginal('status');
            $onlyStatus = array_diff(array_keys($activity->getDirty()), ['status', 'updated_at']) === [];

            if ($original !== self::Draft && ! ($original === self::Final && $activity->status === self::Superseded && $onlyStatus)) {
                throw new LogicException('A finalized processing activity cannot be changed: create a new version.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'data_subjects' => 'array',
            'data_categories' => 'array',
            'special_categories' => 'boolean',
            'purposes' => 'array',
            'legal_bases' => 'array',
            'collection_methods' => 'array',
            'recipients' => 'array',
            'finalized_at' => 'datetime',
        ];
    }
}
