<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

trait ResolvesParkContext
{
    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function message(string $key): string
    {
        return __('adventure_parks.messages.'.$key);
    }

    /**
     * The day asked for (`date` query parameter), today by default.
     */
    private function day(Request $request): CarbonImmutable
    {
        $date = $request->query('date');

        return is_string($date) && $date !== '' ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay() : CarbonImmutable::today();
    }
}
