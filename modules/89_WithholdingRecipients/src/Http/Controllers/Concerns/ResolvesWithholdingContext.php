<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesWithholdingContext
{
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
        return __('withholding_recipients.messages.'.$key);
    }
}
