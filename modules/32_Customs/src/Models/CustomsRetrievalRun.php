<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

final class CustomsRetrievalRun extends CustomsRecord
{
    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'errors' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
