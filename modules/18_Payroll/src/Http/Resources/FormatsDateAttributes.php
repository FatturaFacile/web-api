<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Dates of the payroll records are shown as Y-m-d, like the `date:Y-m-d` casts of the models serialise them.
 */
trait FormatsDateAttributes
{
    private function dateOnly(string $attribute): ?string
    {
        $record = $this->resource;
        $value = $record instanceof Model ? $record->getAttribute($attribute) : null;

        return $value instanceof CarbonInterface ? $value->format('Y-m-d') : null;
    }
}
