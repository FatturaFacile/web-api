<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A value set by the law for a year, such as a threshold, a ceiling, a rate or a retention period, with the source it
 * was taken from. Generic rows are shared and read-only; a company can record its own value for a year.
 *
 * @property string $code
 * @property int $year
 * @property string $value
 * @property ?string $source_reference
 */
final class LegalParameter extends AssociationRecord
{
    protected $table = 'assoc_legal_parameters';

    protected function casts(): array
    {
        return ['year' => 'integer'];
    }
}
