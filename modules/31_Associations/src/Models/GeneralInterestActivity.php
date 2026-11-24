<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * An activity the statute provides for, classified as of general interest (with the reference of the list in the law,
 * such as the letter) or as a secondary activity, with the cost centre that separates its accounts.
 *
 * @property string $kind
 * @property string $reference
 * @property string $description
 */
final class GeneralInterestActivity extends AssociationRecord
{
    public const string GeneralInterest = 'general_interest';

    public const string Secondary = 'secondary';

    protected $table = 'assoc_general_interest_activities';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
