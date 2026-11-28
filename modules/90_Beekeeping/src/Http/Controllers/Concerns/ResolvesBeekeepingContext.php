<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Beekeeping\Models\Apiary;

trait ResolvesBeekeepingContext
{
    private function authorizeRead(): void
    {
        Gate::authorize('viewAny', Apiary::class);
    }

    private function authorizeWrite(): void
    {
        Gate::authorize('manage', Apiary::class);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function locale(Request $request): string
    {
        $locale = (string) ($request->input('locale') ?? app()->getLocale());

        return in_array($locale, ['it', 'en', 'de', 'fr'], true) ? $locale : 'it';
    }

    private function message(string $key): string
    {
        return __('beekeeping.messages.'.$key);
    }
}
